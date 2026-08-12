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
        $usersHasBusinessClientId = Schema::hasColumn('users', 'business_client_id');
        $workspacesHasBusinessId = Schema::hasColumn('workspaces', 'business_id');

        if (!$usersHasBusinessClientId) {
            Schema::table('users', function (Blueprint $table) use ($driver): void {
                if ($driver === 'mysql') {
                    $table->string('business_client_id', 100)->nullable()->after('business_id');
                } else {
                    $table->string('business_client_id', 100)->nullable();
                }
            });
        }

        $businessClientIdsById = DB::table('businesses')->pluck('business_client_id', 'id');
        $users = DB::table('users')->select('id', 'business_id', 'business_client_id')->get();

        foreach ($users as $user) {
            if ($user->business_client_id !== null) {
                continue;
            }

            $businessClientId = $user->business_id ? ($businessClientIdsById[$user->business_id] ?? null) : null;

            DB::table('users')
                ->where('id', $user->id)
                ->update(['business_client_id' => $businessClientId]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->index('business_client_id', 'ix_users_business_client_id');
        });

        if ($driver !== 'sqlite') {
            Schema::table('businesses', function (Blueprint $table): void {
                $table->dropUnique('uq_business_name');
            });
        }

        if (!$workspacesHasBusinessId) {
            Schema::table('workspaces', function (Blueprint $table) use ($driver): void {
                if ($driver === 'mysql') {
                    $table->uuid('business_id')->nullable()->after('id');
                } else {
                    $table->uuid('business_id')->nullable();
                }
            });
        }

        $businessIdsByClientId = DB::table('businesses')->pluck('id', 'business_client_id');
        $workspaces = DB::table('workspaces')->select('id', 'business_client_id')->get();
        $unmappedWorkspaceIds = [];

        foreach ($workspaces as $workspace) {
            $businessId = $workspace->business_client_id
                ? ($businessIdsByClientId[$workspace->business_client_id] ?? null)
                : null;

            if ($businessId === null) {
                $unmappedWorkspaceIds[] = $workspace->id;
                continue;
            }

            DB::table('workspaces')
                ->where('id', $workspace->id)
                ->update(['business_id' => $businessId]);
        }

        if ($unmappedWorkspaceIds !== []) {
            throw new RuntimeException(
                'Cannot align workspaces.business_id because some rows do not map to businesses.business_client_id: '
                .implode(', ', $unmappedWorkspaceIds)
            );
        }

        Schema::table('workspaces', function (Blueprint $table) use ($driver): void {
            if ($driver !== 'sqlite') {
                $table->dropUnique('uq_workspace_business_client');
            }

            $table->index('business_client_id', 'ix_workspaces_business_client_id');
            $table->unique(['business_id', 'workspace_id'], 'uq_workspace_business');

            if ($driver !== 'sqlite') {
                $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            }
        });

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE workspaces MODIFY business_id CHAR(36) NOT NULL');
            DB::statement('ALTER TABLE workspaces MODIFY business_client_id VARCHAR(100) NOT NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver !== 'sqlite') {
            Schema::table('workspaces', function (Blueprint $table): void {
                $table->dropForeign(['business_id']);
            });
        }

        Schema::table('workspaces', function (Blueprint $table) use ($driver): void {
            $table->dropUnique('uq_workspace_business');
            $table->dropIndex('ix_workspaces_business_client_id');
            $table->dropColumn('business_id');

            if ($driver !== 'sqlite') {
                $table->unique(['business_client_id', 'workspace_id'], 'uq_workspace_business_client');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('ix_users_business_client_id');
            $table->dropColumn('business_client_id');
        });

        if ($driver !== 'sqlite') {
            Schema::table('businesses', function (Blueprint $table): void {
                $table->unique('name', 'uq_business_name');
            });
        }
    }
};