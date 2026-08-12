<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\SystemConfig;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Public signup refuses to run until an admin picks the business and workspace that new
 * site users are placed in. Seeding those keys keeps /signup usable on a fresh install.
 */
class SiteUserDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $business = Business::query()->orderBy('created_at')->first();
        if (! $business) {
            return;
        }

        $workspace = Workspace::query()
            ->where('business_id', $business->id)
            ->orderBy('created_at')
            ->first();

        if (! $workspace) {
            return;
        }

        $this->put('SITE_USER_BUSINESS_CLIENT_ID', (string) $business->business_client_id);
        $this->put('SITE_USER_WORKSPACE_ID', (string) $workspace->workspace_id);
    }

    private function put(string $key, string $value): void
    {
        $existing = SystemConfig::query()->where('key', $key)->first();
        if ($existing && trim((string) $existing->value) !== '') {
            return;
        }

        SystemConfig::query()->updateOrCreate(
            ['key' => $key],
            ['id' => (string) Str::uuid(), 'value' => $value]
        );
    }
}
