<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateNames = DB::table('businesses')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name');

        if ($duplicateNames->isNotEmpty()) {
            throw new RuntimeException('Cannot add unique businesses.name index. Duplicate names: '.$duplicateNames->implode(', '));
        }

        Schema::table('businesses', function (Blueprint $table): void {
            $table->unique('name', 'uq_business_name');
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('business_name', 255)->nullable();
        });

        $businessNamesById = DB::table('businesses')->pluck('name', 'id');
        $workspaces = DB::table('workspaces')->select('id', 'business_id')->get();

        foreach ($workspaces as $workspace) {
            $businessName = $businessNamesById[$workspace->business_id] ?? null;

            DB::table('workspaces')
                ->where('id', $workspace->id)
                ->update(['business_name' => $businessName]);
        }

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropForeign(['business_id']);
            $table->dropUnique('uq_workspace_business');
            $table->dropColumn('business_id');
            $table->unique(['business_name', 'workspace_id'], 'uq_workspace_business_name');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropUnique('uq_workspace_business_name');
            $table->uuid('business_id')->nullable();
        });

        $businessIdsByName = DB::table('businesses')->pluck('id', 'name');
        $workspaces = DB::table('workspaces')->select('id', 'business_name')->get();

        foreach ($workspaces as $workspace) {
            $businessId = $businessIdsByName[$workspace->business_name] ?? null;

            DB::table('workspaces')
                ->where('id', $workspace->id)
                ->update(['business_id' => $businessId]);
        }

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('business_name');
            $table->unique(['business_id', 'workspace_id'], 'uq_workspace_business');
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropUnique('uq_business_name');
        });
    }
};
