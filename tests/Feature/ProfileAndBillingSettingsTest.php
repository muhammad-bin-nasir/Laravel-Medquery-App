<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\JwtTokenService;
use App\Services\StripeBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ProfileAndBillingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('JWT_SECRET_KEY=test-secret-key');
        $_ENV['JWT_SECRET_KEY'] = 'test-secret-key';
        $_SERVER['JWT_SECRET_KEY'] = 'test-secret-key';
    }

    public function test_user_can_update_display_name_and_change_password(): void
    {
        $user = $this->makeUser();
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/auth/me', ['display_name' => 'Ada Nurse'])
            ->assertOk()
            ->assertJsonPath('display_name', 'Ada Nurse')
            ->assertJsonPath('email', $user->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'User@12345',
                'password' => 'NewPass@12345',
                'password_confirmation' => 'NewPass@12345',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'User@12345',
                'password' => 'Another@12345',
                'password_confirmation' => 'Another@12345',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_current_password');
    }

    public function test_me_includes_enriched_subscription_usage(): void
    {
        $user = $this->makeUser();
        $plan = Plan::query()->create([
            'id' => (string) Str::uuid(),
            'slug' => 'pro',
            'name' => 'Pro',
            'description' => 'Pro plan',
            'monthly_token_limit' => 1_000_000,
            'price_cents' => 300,
            'currency' => 'usd',
            'openai_usd_per_million' => 1,
            'markup_multiplier' => 3,
            'features' => ['Priority chat', 'More tokens'],
            'is_active' => true,
            'is_highlighted' => true,
            'sort_order' => 2,
        ]);

        $subscription = Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'stripe_payment_intent_id' => 'pi_test_123',
            'tokens_included' => 1_000_000,
            'tokens_used' => 250_000,
            'amount_cents' => 300,
            'currency' => 'usd',
            'current_period_start' => Carbon::now()->subDay(),
            'current_period_end' => Carbon::now()->addMonth(),
        ]);

        $user->plan = 'pro';
        $user->subscription_id = $subscription->id;
        $user->save();

        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('subscription.tokens_used', 250000)
            ->assertJsonPath('subscription.tokens_remaining', 750000)
            ->assertJsonPath('subscription.usage_percent', 25)
            ->assertJsonPath('subscription.plan.price_display', '$3.00')
            ->assertJsonPath('subscription.plan.features.0', 'Priority chat');
    }

    public function test_payment_method_endpoint_returns_safe_card_metadata(): void
    {
        $user = $this->makeUser([
            'stripe_customer_id' => 'cus_test',
            'stripe_payment_method_id' => 'pm_test',
        ]);

        $mock = Mockery::mock(StripeBillingService::class);
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('paymentMethodForUser')->once()->andReturn([
            'id' => 'pm_test',
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
        ]);
        $this->app->instance(StripeBillingService::class, $mock);

        $token = $this->tokenFor($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/payments/payment-method')
            ->assertOk()
            ->assertJsonPath('payment_method.brand', 'visa')
            ->assertJsonPath('payment_method.last4', '4242');

        $payload = $response->json('payment_method');
        $this->assertArrayNotHasKey('number', $payload ?? []);
        $this->assertArrayNotHasKey('cvc', $payload ?? []);
    }

    public function test_user_and_admin_login_portals_enforce_roles(): void
    {
        $user = $this->makeUser();
        $admin = User::query()->where('email_normalized', 'admin-settings@test.local')->firstOrFail();

        $this->postJson('/api/admin/auth/login', [
            'email' => $user->email,
            'password' => 'User@12345',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'user_login_required');

        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'Admin@12345',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'admin_portal_required');

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'User@12345',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', 'user');

        $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'Admin@12345',
        ])
            ->assertOk()
            ->assertJsonPath('role', 'admin');
    }

    private function makeUser(array $overrides = []): User
    {
        $admin = User::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'admin-settings@test.local',
            'email_normalized' => 'admin-settings@test.local',
            'password_hash' => Hash::make('Admin@12345'),
            'role' => 'admin',
        ]);

        $business = Business::query()->create([
            'id' => (string) Str::uuid(),
            'business_client_id' => 'biz-settings',
            'name' => 'Settings Biz',
            'admin_id' => $admin->id,
        ]);

        $workspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'business_client_id' => $business->business_client_id,
            'workspace_id' => 'ws-settings',
            'name' => 'Main',
        ]);

        return User::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'email' => 'settings-user@test.local',
            'email_normalized' => 'settings-user@test.local',
            'display_name' => 'Settings User',
            'password_hash' => Hash::make('User@12345'),
            'role' => 'user',
            'business_id' => $business->id,
            'business_client_id' => $business->business_client_id,
            'workspace_id' => $workspace->id,
        ], $overrides));
    }

    private function tokenFor(User $user): string
    {
        return app(JwtTokenService::class)->createForUser($user)['access_token'];
    }
}
