<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds the first Laravel administrator so /admin/signin works after a fresh DB.
 * Default credentials: admin@example.com / Admin@12345
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'admin@example.com';
        $password = 'Admin@12345';
        $normalized = strtolower($email);

        $existing = User::query()->where('email_normalized', $normalized)->first();
        if ($existing) {
            $existing->role = 'super_admin';
            $existing->password_hash = Hash::make($password);
            $existing->display_name = $existing->display_name ?: 'Administrator';
            if (! $existing->external_id) {
                $existing->external_id = (string) Str::uuid();
            }
            $existing->save();

            $this->command?->info("Updated existing admin: {$email} / {$password}");

            return;
        }

        User::query()->create([
            'id' => (string) Str::uuid(),
            // Prefer linking to FastAPI's seeded admin id when known; otherwise a random
            // UUID is fine because Project API auth also accepts an email JWT claim.
            'external_id' => (string) Str::uuid(),
            'email' => $email,
            'email_normalized' => $normalized,
            'display_name' => 'Administrator',
            'password_hash' => Hash::make($password),
            'role' => 'super_admin',
            'is_active' => true,
            'business_id' => null,
            'business_client_id' => null,
            'workspace_id' => null,
        ]);

        $this->command?->info("Seeded admin: {$email} / {$password}");
    }
}
