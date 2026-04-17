<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (!in_array($driver, ['mysql', 'pgsql'], true)) {
            return;
        }

        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_role_scope CHECK (
            (role = 'user' AND business_id IS NOT NULL AND workspace_id IS NOT NULL)
            OR (role IN ('admin', 'super_admin') AND business_id IS NULL AND workspace_id IS NULL)
            OR (role NOT IN ('user', 'admin', 'super_admin'))
        )");
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE users DROP CHECK chk_users_role_scope');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_role_scope');
        }
    }
};
