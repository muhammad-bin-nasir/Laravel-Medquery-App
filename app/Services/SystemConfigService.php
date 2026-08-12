<?php

namespace App\Services;

use App\Models\SystemConfig;

class SystemConfigService
{
    /**
     * Application settings stored in system_config.
     * Add new keys here as requirements grow — defaults apply until an admin saves a value.
     */
    public const DEFAULTS = [
        'SITE_NAME' => 'NursingAI',
        'SUPPORT_EMAIL' => '',
        'MAINTENANCE_MODE' => '0',
        'MAINTENANCE_MESSAGE' => 'We are performing scheduled maintenance. Please try again soon.',
        'USER_SIGNUP_ENABLED' => '1',
        'USER_CHAT_ENABLED' => '1',
        'ADMIN_CHAT_ENABLED' => '1',
        'HELP_TICKETS_ENABLED' => '1',
        'REQUIRE_PLAN_FOR_CHAT' => '1',
        'VOICE_CHAT_ENABLED' => '1',
    ];

    /** Keys safe to expose without authentication. */
    public const PUBLIC_KEYS = [
        'SITE_NAME',
        'SUPPORT_EMAIL',
        'MAINTENANCE_MODE',
        'MAINTENANCE_MESSAGE',
        'USER_SIGNUP_ENABLED',
        'USER_CHAT_ENABLED',
        'ADMIN_CHAT_ENABLED',
        'HELP_TICKETS_ENABLED',
        'REQUIRE_PLAN_FOR_CHAT',
        'VOICE_CHAT_ENABLED',
    ];

    /** Boolean toggles accepted by the admin update endpoint. */
    public const BOOLEAN_KEYS = [
        'MAINTENANCE_MODE',
        'USER_SIGNUP_ENABLED',
        'USER_CHAT_ENABLED',
        'ADMIN_CHAT_ENABLED',
        'HELP_TICKETS_ENABLED',
        'REQUIRE_PLAN_FOR_CHAT',
        'VOICE_CHAT_ENABLED',
    ];

    /** String fields accepted by the admin update endpoint. */
    public const STRING_KEYS = [
        'SITE_NAME',
        'SUPPORT_EMAIL',
        'MAINTENANCE_MESSAGE',
    ];

    public function get(string $key, ?string $default = null): string
    {
        $row = SystemConfig::query()->where('key', $key)->first();
        if ($row !== null) {
            return trim((string) ($row->value ?? ''));
        }

        if ($default !== null) {
            return $default;
        }

        return (string) (self::DEFAULTS[$key] ?? '');
    }

    public function getBool(string $key, ?bool $default = null): bool
    {
        $fallback = $default;
        if ($fallback === null) {
            $fallback = $this->toBool((string) (self::DEFAULTS[$key] ?? '0'));
        }

        $raw = $this->get($key, $fallback ? '1' : '0');

        return $this->toBool($raw);
    }

    public function set(string $key, string $value): void
    {
        SystemConfig::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    public function setBool(string $key, bool $value): void
    {
        $this->set($key, $value ? '1' : '0');
    }

    /**
     * @return array<string, mixed>
     */
    public function allAppSettings(): array
    {
        $settings = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $this->get($key, $default);
            $settings[$this->toApiKey($key)] = in_array($key, self::BOOLEAN_KEYS, true)
                ? $this->toBool($value)
                : $value;
        }

        return $settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function publicSettings(): array
    {
        $settings = [];
        foreach (self::PUBLIC_KEYS as $key) {
            $value = $this->get($key, (string) (self::DEFAULTS[$key] ?? ''));
            $settings[$this->toApiKey($key)] = in_array($key, self::BOOLEAN_KEYS, true)
                ? $this->toBool($value)
                : $value;
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateAppSettings(array $payload): array
    {
        foreach (self::BOOLEAN_KEYS as $key) {
            $raw = $this->payloadValue($payload, $key);
            if ($raw === null && ! $this->payloadHas($payload, $key)) {
                continue;
            }
            $this->setBool($key, $this->toBool($raw));
        }

        foreach (self::STRING_KEYS as $key) {
            if (! $this->payloadHas($payload, $key)) {
                continue;
            }
            $raw = $this->payloadValue($payload, $key);
            // Nullable fields (e.g. support_email) may arrive as null — store as empty string.
            $this->set($key, trim((string) ($raw ?? '')));
        }

        return $this->allAppSettings();
    }

    private function payloadHas(array $payload, string $key): bool
    {
        return array_key_exists($this->toApiKey($key), $payload) || array_key_exists($key, $payload);
    }

    private function payloadValue(array $payload, string $key): mixed
    {
        $apiKey = $this->toApiKey($key);
        if (array_key_exists($apiKey, $payload)) {
            return $payload[$apiKey];
        }
        if (array_key_exists($key, $payload)) {
            return $payload[$key];
        }

        return null;
    }

    public function openaiApiKey(): string
    {
        $databaseValue = $this->get('OPENAI_API_KEY', '');
        if ($databaseValue !== '') {
            return $databaseValue;
        }

        return trim((string) env('OPENAI_API_KEY', ''));
    }

    public function isStripeEnabled(): bool
    {
        return $this->getBool('STRIPE_ENABLED', true);
    }

    public function stripeEnvironment(): string
    {
        $env = strtolower($this->get('STRIPE_ENVIRONMENT', 'test'));

        return in_array($env, ['test', 'live'], true) ? $env : 'test';
    }

    public function stripePublishableKey(): string
    {
        if (! $this->isStripeEnabled()) {
            return '';
        }

        $mode = $this->stripeEnvironment();
        $dbKey = $mode === 'live'
            ? $this->get('STRIPE_LIVE_PUBLISHABLE_KEY')
            : $this->get('STRIPE_TEST_PUBLISHABLE_KEY');

        if ($dbKey !== '' && ! str_contains($dbKey, 'placeholder')) {
            return $dbKey;
        }

        // Legacy single-key env fallback (typically test).
        $legacy = trim((string) env('STRIPE_PUBLISHABLE_KEY', config('services.stripe.publishable_key', '')));
        if ($mode === 'test' && $legacy !== '' && ! str_contains($legacy, 'placeholder')) {
            return $legacy;
        }

        return '';
    }

    public function stripeSecretKey(): string
    {
        if (! $this->isStripeEnabled()) {
            return '';
        }

        $mode = $this->stripeEnvironment();
        $dbKey = $mode === 'live'
            ? $this->get('STRIPE_LIVE_SECRET_KEY')
            : $this->get('STRIPE_TEST_SECRET_KEY');

        if ($dbKey !== '' && ! str_contains($dbKey, 'placeholder')) {
            return $dbKey;
        }

        $legacy = trim((string) env('STRIPE_SECRET_KEY', config('services.stripe.secret', '')));
        if ($mode === 'test' && $legacy !== '' && ! str_contains($legacy, 'placeholder')) {
            return $legacy;
        }

        return '';
    }

    public function isStripeConfigured(): bool
    {
        $secret = $this->stripeSecretKey();
        $publishable = $this->stripePublishableKey();

        return $secret !== '' && $publishable !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function stripeSettingsStatus(): array
    {
        $testPub = $this->get('STRIPE_TEST_PUBLISHABLE_KEY');
        $testSec = $this->get('STRIPE_TEST_SECRET_KEY');
        $livePub = $this->get('STRIPE_LIVE_PUBLISHABLE_KEY');
        $liveSec = $this->get('STRIPE_LIVE_SECRET_KEY');
        $legacyPub = trim((string) env('STRIPE_PUBLISHABLE_KEY', ''));
        $legacySec = trim((string) env('STRIPE_SECRET_KEY', ''));

        return [
            'enabled' => $this->isStripeEnabled(),
            'environment' => $this->stripeEnvironment(),
            'configured' => $this->isStripeConfigured(),
            'active_publishable_masked' => $this->maskSecret($this->stripePublishableKey()),
            'test' => [
                'publishable_masked' => $this->maskSecret($testPub !== '' ? $testPub : $legacyPub),
                'secret_masked' => $this->maskSecret($testSec !== '' ? $testSec : $legacySec),
                'has_publishable' => ($testPub !== '' && ! str_contains($testPub, 'placeholder'))
                    || ($legacyPub !== '' && ! str_contains($legacyPub, 'placeholder')),
                'has_secret' => ($testSec !== '' && ! str_contains($testSec, 'placeholder'))
                    || ($legacySec !== '' && ! str_contains($legacySec, 'placeholder')),
                'source' => $testPub !== '' || $testSec !== '' ? 'database' : ($legacyPub !== '' || $legacySec !== '' ? 'env' : null),
            ],
            'live' => [
                'publishable_masked' => $this->maskSecret($livePub),
                'secret_masked' => $this->maskSecret($liveSec),
                'has_publishable' => $livePub !== '' && ! str_contains($livePub, 'placeholder'),
                'has_secret' => $liveSec !== '' && ! str_contains($liveSec, 'placeholder'),
                'source' => $livePub !== '' || $liveSec !== '' ? 'database' : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateStripeSettings(array $payload): array
    {
        if (array_key_exists('enabled', $payload)) {
            $this->setBool('STRIPE_ENABLED', $this->toBool($payload['enabled']));
        }

        if (array_key_exists('environment', $payload)) {
            $env = strtolower(trim((string) $payload['environment']));
            if (in_array($env, ['test', 'live'], true)) {
                $this->set('STRIPE_ENVIRONMENT', $env);
            }
        }

        $map = [
            'test_publishable_key' => 'STRIPE_TEST_PUBLISHABLE_KEY',
            'test_secret_key' => 'STRIPE_TEST_SECRET_KEY',
            'live_publishable_key' => 'STRIPE_LIVE_PUBLISHABLE_KEY',
            'live_secret_key' => 'STRIPE_LIVE_SECRET_KEY',
        ];

        foreach ($map as $input => $configKey) {
            if (! array_key_exists($input, $payload)) {
                continue;
            }
            $value = trim((string) ($payload[$input] ?? ''));
            // Empty string means "leave unchanged" so admins can flip env without re-pasting secrets.
            if ($value === '') {
                continue;
            }
            $this->set($configKey, $value);
        }

        return $this->stripeSettingsStatus();
    }

    public function isTurnstileEnabled(): bool
    {
        return $this->getBool('TURNSTILE_ENABLED', true);
    }

    public function turnstileSiteKey(): string
    {
        if (! $this->isTurnstileEnabled()) {
            return '';
        }

        $db = $this->get('TURNSTILE_SITE_KEY');
        if ($db !== '') {
            return $db;
        }

        return trim((string) env('TURNSTILE_SITE_KEY', env('RECAPTCHA_SITE_KEY', '')));
    }

    public function turnstileSecretKey(): string
    {
        if (! $this->isTurnstileEnabled()) {
            return '';
        }

        $db = $this->get('TURNSTILE_SECRET_KEY');
        if ($db !== '') {
            return $db;
        }

        return trim((string) env('TURNSTILE_SECRET_KEY', env('RECAPTCHA_SECRET_KEY', '')));
    }

    public function isTurnstileConfigured(): bool
    {
        return $this->turnstileSiteKey() !== '' && $this->turnstileSecretKey() !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function turnstileSettingsStatus(): array
    {
        $site = $this->get('TURNSTILE_SITE_KEY');
        $secret = $this->get('TURNSTILE_SECRET_KEY');
        $envSite = trim((string) env('TURNSTILE_SITE_KEY', ''));
        $envSecret = trim((string) env('TURNSTILE_SECRET_KEY', ''));

        return [
            'enabled' => $this->isTurnstileEnabled(),
            'configured' => $this->isTurnstileConfigured(),
            'site_key_masked' => $this->maskSecret($this->turnstileSiteKey()),
            'secret_masked' => $this->maskSecret($this->turnstileSecretKey()),
            'has_site_key' => $this->turnstileSiteKey() !== '',
            'has_secret' => $this->turnstileSecretKey() !== '',
            'source' => $site !== '' || $secret !== ''
                ? 'database'
                : (($envSite !== '' || $envSecret !== '') ? 'env' : null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateTurnstileSettings(array $payload): array
    {
        if (array_key_exists('enabled', $payload)) {
            $this->setBool('TURNSTILE_ENABLED', $this->toBool($payload['enabled']));
        }

        if (array_key_exists('site_key', $payload)) {
            $value = trim((string) ($payload['site_key'] ?? ''));
            if ($value !== '') {
                $this->set('TURNSTILE_SITE_KEY', $value);
            }
        }

        if (array_key_exists('secret_key', $payload)) {
            $value = trim((string) ($payload['secret_key'] ?? ''));
            if ($value !== '') {
                $this->set('TURNSTILE_SECRET_KEY', $value);
            }
        }

        return $this->turnstileSettingsStatus();
    }

    public function maskSecret(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, 'placeholder')) {
            return null;
        }

        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        // Keep display short so long keys don't overflow UI cards.
        return substr($value, 0, 4).str_repeat('*', 8).substr($value, -4);
    }

    public function isMaintenanceMode(): bool
    {
        return $this->getBool('MAINTENANCE_MODE');
    }

    public function maintenanceMessage(): string
    {
        $message = $this->get('MAINTENANCE_MESSAGE');

        return $message !== ''
            ? $message
            : (string) self::DEFAULTS['MAINTENANCE_MESSAGE'];
    }

    public function isUserSignupEnabled(): bool
    {
        return $this->getBool('USER_SIGNUP_ENABLED');
    }

    public function isHelpTicketsEnabled(): bool
    {
        return $this->getBool('HELP_TICKETS_ENABLED');
    }

    public function isRequirePlanForChat(): bool
    {
        return $this->getBool('REQUIRE_PLAN_FOR_CHAT');
    }

    public function isVoiceChatEnabled(): bool
    {
        return $this->getBool('VOICE_CHAT_ENABLED');
    }

    public function isChatEnabledForRole(?string $role): bool
    {
        $normalized = strtolower(trim((string) $role));
        if (in_array($normalized, ['admin', 'super_admin', 'sub_admin'], true)) {
            return $this->getBool('ADMIN_CHAT_ENABLED');
        }

        return $this->getBool('USER_CHAT_ENABLED');
    }

    public function toApiKey(string $key): string
    {
        return strtolower($key);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
