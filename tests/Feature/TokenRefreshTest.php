<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('JWT_SECRET_KEY=test-secret-key');
        $_ENV['JWT_SECRET_KEY'] = 'test-secret-key';
        $_SERVER['JWT_SECRET_KEY'] = 'test-secret-key';

        putenv('JWT_ACCESS_TOKEN_EXPIRE_MINUTES=60');
        $_ENV['JWT_ACCESS_TOKEN_EXPIRE_MINUTES'] = '60';
        $_SERVER['JWT_ACCESS_TOKEN_EXPIRE_MINUTES'] = '60';

        putenv('JWT_REFRESH_GRACE_MINUTES=60');
        $_ENV['JWT_REFRESH_GRACE_MINUTES'] = '60';
        $_SERVER['JWT_REFRESH_GRACE_MINUTES'] = '60';
    }

    public function test_valid_token_can_refresh_and_receive_new_session(): void
    {
        $user = $this->makeUser();
        $session = app(JwtTokenService::class)->createForUser($user);
        $token = $session['access_token'];
        $originalExpiry = (int) $session['expires_at'];

        $this->travel(5)->minutes();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/refresh');

        $response->assertOk()
            ->assertJsonStructure([
                'session' => ['access_token', 'expires_at', 'expires_in'],
            ]);

        $this->assertGreaterThan($originalExpiry, (int) $response->json('session.expires_at'));
    }

    public function test_expired_token_within_grace_can_refresh(): void
    {
        $user = $this->makeUser();
        $token = app(JwtTokenService::class)->createForUser($user)['access_token'];

        $this->travel(90)->minutes();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonStructure([
                'session' => ['access_token', 'expires_at', 'expires_in'],
            ]);
    }

    public function test_expired_token_outside_grace_cannot_refresh(): void
    {
        $user = $this->makeUser();
        $token = app(JwtTokenService::class)->createForUser($user)['access_token'];

        $this->travel(3)->hours();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/refresh')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'token_expired');
    }

    public function test_expired_token_is_rejected_on_protected_routes(): void
    {
        $user = $this->makeUser();
        $token = app(JwtTokenService::class)->createForUser($user)['access_token'];

        $this->travel(90)->minutes();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'token_expired');
    }

    public function test_admin_token_can_refresh_via_auth_refresh_endpoint(): void
    {
        $admin = User::query()->create([
            'id' => (string) Str::uuid(),
            'external_id' => (string) Str::uuid(),
            'email' => 'admin@example.com',
            'email_normalized' => 'admin@example.com',
            'password_hash' => Hash::make('Admin@12345'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $token = app(JwtTokenService::class)->createForUser($admin)['access_token'];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonStructure([
                'session' => ['access_token', 'expires_at', 'expires_in'],
            ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'id' => (string) Str::uuid(),
            'external_id' => (string) Str::uuid(),
            'email' => 'user@example.com',
            'email_normalized' => 'user@example.com',
            'password_hash' => Hash::make('User@12345'),
            'role' => 'user',
            'is_active' => true,
        ]);
    }
}
