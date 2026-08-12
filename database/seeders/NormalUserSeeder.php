<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds a normal site user attached to the default business and workspace.
 * Default credentials: user@example.com / User@12345
 */
class NormalUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'user@example.com';
        $password = 'User@12345';
        $normalized = strtolower($email);

        $business = Business::query()->firstOrCreate(
            ['business_client_id' => 'default'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Default',
            ]
        );

        $workspace = Workspace::query()->firstOrCreate(
            [
                'business_id' => $business->id,
                'workspace_id' => 'default',
            ],
            [
                'business_client_id' => $business->business_client_id,
                'id' => (string) Str::uuid(),
                'name' => 'Default',
            ]
        );

        $existing = User::query()->where('email_normalized', $normalized)->first();
        if ($existing) {
            $existing->role = 'user';
            $existing->password_hash = Hash::make($password);
            $existing->display_name = $existing->display_name ?: 'Normal User';
            $existing->is_active = true;
            $existing->business_id = $business->id;
            $existing->business_client_id = $business->business_client_id;
            $existing->workspace_id = $workspace->id;
            if (! $existing->external_id) {
                $existing->external_id = (string) Str::uuid();
            }
            $existing->save();

            $this->command?->info("Updated existing user: {$email} / {$password}");

            return;
        }

        User::query()->create([
            'id' => (string) Str::uuid(),
            'external_id' => (string) Str::uuid(),
            'email' => $email,
            'email_normalized' => $normalized,
            'display_name' => 'Normal User',
            'password_hash' => Hash::make($password),
            'role' => 'user',
            'is_active' => true,
            'business_id' => $business->id,
            'business_client_id' => $business->business_client_id,
            'workspace_id' => $workspace->id,
        ]);

        $this->command?->info("Seeded user: {$email} / {$password}");
    }
}
