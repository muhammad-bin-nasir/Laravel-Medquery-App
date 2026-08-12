<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailSettingsService
{
    public function __construct(private readonly SystemConfigService $config)
    {
    }

    public function isEnabled(): bool
    {
        return $this->config->getBool('MAIL_ENABLED', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $host = $this->config->get('MAIL_HOST') ?: trim((string) env('MAIL_HOST', ''));
        $username = $this->config->get('MAIL_USERNAME') ?: trim((string) env('MAIL_USERNAME', ''));
        $password = $this->config->get('MAIL_PASSWORD') ?: trim((string) env('MAIL_PASSWORD', ''));
        $from = $this->config->get('MAIL_FROM_ADDRESS') ?: trim((string) env('MAIL_FROM_ADDRESS', ''));

        return [
            'enabled' => $this->isEnabled(),
            'mailer' => $this->config->get('MAIL_MAILER') ?: trim((string) env('MAIL_MAILER', 'smtp')),
            'host' => $host !== '' ? $host : null,
            'port' => (int) ($this->config->get('MAIL_PORT') ?: env('MAIL_PORT', 587)),
            'username_masked' => $this->config->maskSecret($username),
            'has_password' => $password !== '',
            'encryption' => $this->config->get('MAIL_ENCRYPTION') ?: trim((string) env('MAIL_ENCRYPTION', 'tls')),
            'from_address' => $from !== '' ? $from : null,
            'from_name' => $this->config->get('MAIL_FROM_NAME') ?: trim((string) env('MAIL_FROM_NAME', config('app.name', 'NursingAI'))),
            'configured' => $this->isConfigured(),
        ];
    }

    public function isConfigured(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $mailer = strtolower($this->resolve('MAIL_MAILER', (string) env('MAIL_MAILER', 'smtp')));
        if ($mailer === 'log' || $mailer === 'array') {
            return true;
        }

        $host = $this->resolve('MAIL_HOST', (string) env('MAIL_HOST', ''));
        $from = $this->resolve('MAIL_FROM_ADDRESS', (string) env('MAIL_FROM_ADDRESS', ''));

        return $host !== '' && $from !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(array $payload): array
    {
        if (array_key_exists('enabled', $payload)) {
            $this->config->setBool('MAIL_ENABLED', $this->toBool($payload['enabled']));
        }

        foreach ([
            'mailer' => 'MAIL_MAILER',
            'host' => 'MAIL_HOST',
            'port' => 'MAIL_PORT',
            'username' => 'MAIL_USERNAME',
            'encryption' => 'MAIL_ENCRYPTION',
            'from_address' => 'MAIL_FROM_ADDRESS',
            'from_name' => 'MAIL_FROM_NAME',
        ] as $input => $key) {
            if (! array_key_exists($input, $payload)) {
                continue;
            }
            $value = trim((string) ($payload[$input] ?? ''));
            if ($input === 'port' && $value === '') {
                continue;
            }
            // Empty password/username means leave unchanged except from_name/from_address/host/mailer/encryption which can clear.
            if (in_array($input, ['username'], true) && $value === '') {
                continue;
            }
            $this->config->set($key, $value);
        }

        if (array_key_exists('password', $payload)) {
            $password = (string) ($payload['password'] ?? '');
            if (trim($password) !== '') {
                $this->config->set('MAIL_PASSWORD', trim($password));
            }
        }

        return $this->status();
    }

    public function applyRuntimeConfig(): void
    {
        $mailer = strtolower($this->resolve('MAIL_MAILER', (string) env('MAIL_MAILER', 'smtp'))) ?: 'smtp';
        $host = $this->resolve('MAIL_HOST', (string) env('MAIL_HOST', '127.0.0.1'));
        $port = (int) ($this->resolve('MAIL_PORT', (string) env('MAIL_PORT', '587')) ?: 587);
        $username = $this->resolve('MAIL_USERNAME', (string) env('MAIL_USERNAME', ''));
        $password = $this->resolve('MAIL_PASSWORD', (string) env('MAIL_PASSWORD', ''));
        $encryption = strtolower($this->resolve('MAIL_ENCRYPTION', (string) env('MAIL_ENCRYPTION', 'tls')));
        $fromAddress = $this->resolve('MAIL_FROM_ADDRESS', (string) env('MAIL_FROM_ADDRESS', 'noreply@example.com'));
        $fromName = $this->resolve('MAIL_FROM_NAME', (string) env('MAIL_FROM_NAME', config('app.name', 'NursingAI')));

        Config::set('mail.default', $mailer);
        Config::set('mail.mailers.smtp.host', $host);
        Config::set('mail.mailers.smtp.port', $port);
        Config::set('mail.mailers.smtp.username', $username !== '' ? $username : null);
        Config::set('mail.mailers.smtp.password', $password !== '' ? $password : null);
        Config::set('mail.mailers.smtp.encryption', in_array($encryption, ['tls', 'ssl'], true) ? $encryption : null);
        Config::set('mail.mailers.smtp.scheme', in_array($encryption, ['tls', 'ssl'], true) ? $encryption : null);
        Config::set('mail.from.address', $fromAddress);
        Config::set('mail.from.name', $fromName !== '' ? $fromName : 'NursingAI');
    }

    /**
     * @throws Throwable
     */
    public function send(callable $callback): void
    {
        if (! $this->isEnabled()) {
            throw new \RuntimeException('Email sending is disabled in settings.');
        }

        if (! $this->isConfigured()) {
            throw new \RuntimeException('SMTP is not configured. Add mail settings in Admin → Settings.');
        }

        $this->applyRuntimeConfig();
        $callback();
    }

    public function frontendUrl(): string
    {
        $configured = trim((string) (
            $this->config->get('FRONTEND_URL')
            ?: env('FRONTEND_URL')
            ?: env('NEXT_PUBLIC_APP_URL')
            ?: 'http://127.0.0.1:8002'
        ));

        return rtrim($configured, '/');
    }

    private function resolve(string $key, string $fallback = ''): string
    {
        $value = $this->config->get($key);

        return $value !== '' ? $value : trim($fallback);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
