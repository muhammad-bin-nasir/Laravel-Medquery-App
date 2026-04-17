<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConfig;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthorizationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('JWT_SECRET_KEY=test-secret-key');
        $_ENV['JWT_SECRET_KEY'] = 'test-secret-key';
        $_SERVER['JWT_SECRET_KEY'] = 'test-secret-key';
    }

    public function test_admin_cannot_create_user_for_another_admin_business(): void
    {
        $adminA = $this->makeAdmin('admin-a@test.local');
        $adminB = $this->makeAdmin('admin-b@test.local');

        $businessB = $this->makeBusiness('biz-b', 'Business B', $adminB->id);
        $workspaceB = $this->makeWorkspace($businessB->business_client_id, 'main-b');

        $tokenA = $this->tokenFor($adminA);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/admin/auth/create-user', [
                'email' => 'scoped-user@test.local',
                'password' => 'User@12345',
                'business_client_id' => $businessB->business_client_id,
                'workspace_id' => $workspaceB->workspace_id,
            ]);

        $response->assertStatus(403)
            ->assertJson(['detail' => 'Not allowed']);
    }

    public function test_user_cannot_retrieve_rag_for_other_workspace(): void
    {
        $admin = $this->makeAdmin('admin-owner@test.local');
        $business = $this->makeBusiness('biz-a', 'Business A', $admin->id);

        $workspaceAllowed = $this->makeWorkspace($business->business_client_id, 'ws-allowed');
        $workspaceBlocked = $this->makeWorkspace($business->business_client_id, 'ws-blocked');

        WorkspaceConfig::query()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'workspace_id' => $workspaceBlocked->id,
        ]);

        $user = User::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'user@test.local',
            'email_normalized' => 'user@test.local',
            'password_hash' => Hash::make('User@12345'),
            'role' => 'user',
            'business_id' => $business->id,
            'workspace_id' => $workspaceAllowed->id,
        ]);

        $token = $this->tokenFor($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/rag/retrieve', [
                'business_client_id' => $business->business_client_id,
                'workspace_id' => $workspaceBlocked->workspace_id,
                'user_id' => $user->email,
                'query' => 'test query',
                'top_k' => 3,
            ]);

        $response->assertStatus(403)
            ->assertJson(['detail' => 'Not allowed']);
    }

    public function test_super_admin_can_retrieve_rag_across_businesses(): void
    {
        $admin = $this->makeAdmin('admin-owner-2@test.local');
        $business = $this->makeBusiness('biz-global', 'Business Global', $admin->id);
        $workspace = $this->makeWorkspace($business->business_client_id, 'ws-global');

        WorkspaceConfig::query()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'workspace_id' => $workspace->id,
        ]);

        $superAdmin = User::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'super@test.local',
            'email_normalized' => 'super@test.local',
            'password_hash' => Hash::make('Super@12345'),
            'role' => 'super_admin',
            'business_id' => null,
            'workspace_id' => null,
        ]);

        $token = $this->tokenFor($superAdmin);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/rag/retrieve', [
                'business_client_id' => $business->business_client_id,
                'workspace_id' => $workspace->workspace_id,
                'user_id' => 'any-user@test.local',
                'query' => 'test query',
                'top_k' => 3,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'business_client_id' => $business->business_client_id,
                'workspace_id' => $workspace->workspace_id,
            ]);
    }

    public function test_db_constraint_rejects_admin_with_business_or_workspace_scope(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Role-scope check constraint migration applies only to mysql/pgsql.');
        }

        $this->expectException(QueryException::class);

        User::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'invalid-admin@test.local',
            'email_normalized' => 'invalid-admin@test.local',
            'password_hash' => Hash::make('Admin@12345'),
            'role' => 'admin',
            'business_id' => (string) Str::uuid(),
            'workspace_id' => null,
        ]);
    }

    private function tokenFor(User $user): string
    {
        /** @var JwtTokenService $jwt */
        $jwt = app(JwtTokenService::class);
        return $jwt->createForUser($user)['access_token'];
    }

    private function makeAdmin(string $email): User
    {
        return User::query()->create([
            'id' => (string) Str::uuid(),
            'email' => strtolower(trim($email)),
            'email_normalized' => strtolower(trim($email)),
            'password_hash' => Hash::make('Admin@12345'),
            'role' => 'admin',
            'business_id' => null,
            'workspace_id' => null,
        ]);
    }

    private function makeBusiness(string $clientId, string $name, string $adminId): Business
    {
        return Business::query()->create([
            'id' => (string) Str::uuid(),
            'business_client_id' => $clientId,
            'name' => $name,
            'admin_id' => $adminId,
        ]);
    }

    private function makeWorkspace(string $businessClientId, string $workspaceId): Workspace
    {
        return Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'business_client_id' => $businessClientId,
            'workspace_id' => $workspaceId,
            'name' => strtoupper($workspaceId),
        ]);
    }
}
