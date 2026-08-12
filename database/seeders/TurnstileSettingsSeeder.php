<?php

namespace Database\Seeders;

use App\Services\SystemConfigService;
use Illuminate\Database\Seeder;

/**
 * Seeds Cloudflare Turnstile keys into system_config (admin Settings).
 */
class TurnstileSettingsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var SystemConfigService $config */
        $config = app(SystemConfigService::class);

        $siteKey = trim((string) (
            env('TURNSTILE_SITE_KEY')
            ?: '0x4AAAAAAEN0c7Cx1JxvTRvA'
        ));
        $secretKey = trim((string) (
            env('TURNSTILE_SECRET_KEY')
            ?: '0x4AAAAAAEN0c_Tflnhcxbd6lxlS0gStKiQ'
        ));

        $config->setBool('TURNSTILE_ENABLED', true);
        if ($siteKey !== '') {
            $config->set('TURNSTILE_SITE_KEY', $siteKey);
        }
        if ($secretKey !== '') {
            $config->set('TURNSTILE_SECRET_KEY', $secretKey);
        }

        // Ensure Stripe settings exist with sensible defaults (env fallback still works).
        if ($config->get('STRIPE_ENABLED') === '' && ! \App\Models\SystemConfig::query()->where('key', 'STRIPE_ENABLED')->exists()) {
            $config->setBool('STRIPE_ENABLED', true);
        }
        if ($config->get('STRIPE_ENVIRONMENT') === '' && ! \App\Models\SystemConfig::query()->where('key', 'STRIPE_ENVIRONMENT')->exists()) {
            $config->set('STRIPE_ENVIRONMENT', 'test');
        }

        $this->command?->info('Turnstile + Stripe settings seeded into system_config.');
    }
}
