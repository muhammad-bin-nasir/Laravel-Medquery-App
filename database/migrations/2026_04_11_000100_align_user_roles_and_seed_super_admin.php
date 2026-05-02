<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
    }

    public function down(): void
    {
        // Intentionally non-destructive: do not remove super admin or restore previous role scoping.
    }
};
