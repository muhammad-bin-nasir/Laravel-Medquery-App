<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereIn('role', ['admin', 'super_admin'])
            ->update([
                'business_id' => null,
                'workspace_id' => null,
            ]);

        $superAdminEmail = 'superadmin@system.local';

        $existingSuperAdmin = DB::table('users')
            ->where('role', 'super_admin')
            ->first();

        if (!$existingSuperAdmin) {
            $userByEmail = DB::table('users')
                ->where('email_normalized', $superAdminEmail)
                ->first();

            if ($userByEmail) {
                DB::table('users')
                    ->where('id', $userByEmail->id)
                    ->update([
                        'role' => 'super_admin',
                        'business_id' => null,
                        'workspace_id' => null,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('users')->insert([
                    'id' => (string) Str::uuid(),
                    'business_id' => null,
                    'workspace_id' => null,
                    'email' => $superAdminEmail,
                    'email_normalized' => $superAdminEmail,
                    'password_hash' => password_hash('SuperAdmin@12345', PASSWORD_BCRYPT),
                    'role' => 'super_admin',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: do not remove super admin or restore previous role scoping.
    }
};
