<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::table('workspaces', function (Blueprint $table) use ($driver): void {
            if ($driver === 'mysql') {
                $table->string('business_client_id', 100)->nullable()->after('id');
            } else {
                $table->string('business_client_id', 100)->nullable();
            }
        });

        $clientIdsByName = DB::table('businesses')->pluck('business_client_id', 'name');
        $workspaces = DB::table('workspaces')->select('id', 'business_name')->get();

        foreach ($workspaces as $workspace) {
            $businessClientId = $clientIdsByName[$workspace->business_name] ?? null;

            DB::table('workspaces')
                ->where('id', $workspace->id)
                ->update(['business_client_id' => $businessClientId]);
        }

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropUnique('uq_workspace_business_name');
            $table->dropColumn('business_name');
            $table->unique(['business_client_id', 'workspace_id'], 'uq_workspace_business_client');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('business_name', 255)->nullable();
        });

        $namesByClientId = DB::table('businesses')->pluck('name', 'business_client_id');
        $workspaces = DB::table('workspaces')->select('id', 'business_client_id')->get();

        foreach ($workspaces as $workspace) {
            $businessName = $namesByClientId[$workspace->business_client_id] ?? null;

            DB::table('workspaces')
                ->where('id', $workspace->id)
                ->update(['business_name' => $businessName]);
        }

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropUnique('uq_workspace_business_client');
            $table->dropColumn('business_client_id');
            $table->unique(['business_name', 'workspace_id'], 'uq_workspace_business_name');
        });
    }
};
