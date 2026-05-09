<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiGatewayRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('JWT_SECRET_KEY=test-secret-key');
        $_ENV['JWT_SECRET_KEY'] = 'test-secret-key';
        $_SERVER['JWT_SECRET_KEY'] = 'test-secret-key';

        config()->set('services.fastapi.base_url', 'http://fastapi.local');
        config()->set('services.fastapi.max_image_bytes', 1024 * 1024);
    }

    public function test_chat_injects_tenant_context_for_user_scope(): void
    {
        [$user, $business, $workspace] = $this->makeTenantUser();

        Http::fake([
            '*' => Http::response([
                'answer' => 'hello from fastapi',
                'chat_id' => 'chat-123',
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/ai/chat', [
                'business_client_id' => 'frontend-spoof-business',
                'workspace_id' => 'frontend-spoof-workspace',
                'user_id' => 'spoof@frontend.local',
                'query' => 'Hello',
            ]);

        $response->assertOk()
            ->assertJson([
                'answer' => 'hello from fastapi',
                'chat_id' => 'chat-123',
            ]);

        Http::assertSent(function (Request $request) use ($user, $business, $workspace): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'http://fastapi.local/api/chat/generate'
                && $request->hasHeader('X-Correlation-Id')
                && is_array($body)
                && ($body['business_client_id'] ?? null) === $business->business_client_id
                && ($body['workspace_id'] ?? null) === $workspace->workspace_id
                && ($body['user_id'] ?? null) === strtolower($user->email);
        });
    }

    public function test_chat_rejects_malformed_image_data_url(): void
    {
        [$user] = $this->makeTenantUser();

        Http::fake();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/ai/chat', [
                'query' => 'describe this image',
                'image_data_url' => 'not-a-data-url',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_error')
            ->assertJsonPath('message', 'The given data was invalid.');

        Http::assertNothingSent();
    }

    public function test_chat_returns_normalized_upstream_401(): void
    {
        [$user] = $this->makeTenantUser();

        Http::fake([
            '*' => Http::response([
                'detail' => 'Invalid upstream credentials',
            ], 401),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/ai/chat', [
                'query' => 'hello',
            ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'upstream_unauthorized')
            ->assertJsonPath('message', 'Invalid upstream credentials');
    }

    public function test_chat_returns_normalized_timeout_error(): void
    {
        [$user] = $this->makeTenantUser();

        Http::fake(function (): never {
            throw new ConnectionException('cURL error 28: operation timed out');
        });

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/ai/chat', [
                'query' => 'hello',
            ]);

        $response->assertStatus(504)
            ->assertJsonPath('code', 'upstream_timeout')
            ->assertJsonPath('message', 'AI service timed out while processing chat request.');
    }

    public function test_retrieve_route_success_and_upstream_payload(): void
    {
        [$user, $business, $workspace] = $this->makeTenantUser();

        Http::fake([
            '*' => Http::response([
                'retrieved_chunks' => [
                    ['content' => 'chunk 1'],
                ],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/ai/retrieve', [
                'query' => 'what is medquery?',
                'top_k' => 5,
            ]);

        $response->assertOk()
            ->assertJsonPath('retrieved_chunks.0.content', 'chunk 1');

        Http::assertSent(function (Request $request) use ($user, $business, $workspace): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'http://fastapi.local/api/rag/retrieve'
                && is_array($body)
                && ($body['business_client_id'] ?? null) === $business->business_client_id
                && ($body['workspace_id'] ?? null) === $workspace->workspace_id
                && ($body['user_id'] ?? null) === strtolower($user->email)
                && ($body['top_k'] ?? null) === 5;
        });
    }

    public function test_retrieve_returns_normalized_upstream_500(): void
    {
        [$user] = $this->makeTenantUser();

        Http::fake([
            '*' => Http::response([
                'detail' => 'embedding service unavailable',
            ], 500),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/ai/retrieve', [
                'query' => 'any query',
            ]);

        $response->assertStatus(500)
            ->assertJsonPath('code', 'upstream_server_error')
            ->assertJsonPath('message', 'embedding service unavailable');
    }

    public function test_stream_route_proxies_sse_payload(): void
    {
        [$user] = $this->makeTenantUser();

        $sse = 'data: {"token":"hello"}' . "\n\n" .
            'event: done' . "\n" .
            'data: {"sources":[]}' . "\n\n";

        Http::fake([
            '*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/ai/chat/stream', [
                'query' => 'stream this answer',
            ]);

        $response->assertOk();
        $streamed = $response->streamedContent();
        $this->assertStringContainsString('data: {"token":"hello"}', $streamed);
        $this->assertStringContainsString('event: done', $streamed);
    }

    public function test_ai_route_without_authenticated_context_returns_401_shape(): void
    {
        $response = $this->postJson('/api/ai/chat', [
            'query' => 'hello',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'unauthorized')
            ->assertJsonPath('message', 'No admin found');
    }

    /**
     * @return array{0: User, 1: Business, 2: Workspace}
     */
    private function makeTenantUser(): array
    {
        $admin = User::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'admin@test.local',
            'email_normalized' => 'admin@test.local',
            'password_hash' => Hash::make('Admin@12345'),
            'role' => 'admin',
            'business_id' => null,
            'business_client_id' => null,
            'workspace_id' => null,
        ]);

        $business = Business::query()->create([
            'id' => (string) Str::uuid(),
            'business_client_id' => 'tenant-biz-1',
            'name' => 'Tenant Biz',
            'admin_id' => $admin->id,
        ]);

        $workspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'business_client_id' => $business->business_client_id,
            'workspace_id' => 'workspace-public-1',
            'name' => 'Primary Workspace',
        ]);

        $user = User::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'user@test.local',
            'email_normalized' => 'user@test.local',
            'password_hash' => Hash::make('User@12345'),
            'role' => 'user',
            'business_id' => $business->id,
            'business_client_id' => $business->business_client_id,
            'workspace_id' => $workspace->id,
        ]);

        return [$user, $business, $workspace];
    }

    private function tokenFor(User $user): string
    {
        /** @var JwtTokenService $jwt */
        $jwt = app(JwtTokenService::class);

        return $jwt->createForUser($user)['access_token'];
    }
}
