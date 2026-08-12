<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('role');
            }
            if (! Schema::hasColumn('users', 'created_by')) {
                $table->uuid('created_by')->nullable()->after('is_active');
                $table->index('created_by');
            }
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_role_scope');
            DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_role_scope CHECK (
                (role = 'user' AND business_id IS NOT NULL AND workspace_id IS NOT NULL)
                OR (role IN ('admin', 'super_admin', 'sub_admin') AND business_id IS NULL AND workspace_id IS NULL)
                OR (role NOT IN ('user', 'admin', 'super_admin', 'sub_admin'))
            )");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_role_scope');
            DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_role_scope CHECK (
                (role = 'user' AND business_id IS NOT NULL AND workspace_id IS NOT NULL)
                OR (role IN ('admin', 'super_admin') AND business_id IS NULL AND workspace_id IS NULL)
                OR (role NOT IN ('user', 'admin', 'super_admin'))
            )");
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'created_by')) {
                $table->dropIndex(['created_by']);
                $table->dropColumn('created_by');
            }
            if (Schema::hasColumn('users', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
