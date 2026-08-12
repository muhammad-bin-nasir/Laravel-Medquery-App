<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ProtectsPublicAuth;
use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Services\JwtTokenService;
use App\Services\MailSettingsService;
use App\Services\StripeBillingService;
use App\Services\SubscriptionService;
use App\Services\SystemConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AuthController extends Controller
{
    use ProtectsPublicAuth;

    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly SubscriptionService $subscriptions,
        private readonly StripeBillingService $stripe,
        private readonly MailSettingsService $mailSettings,
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $blocked = $this->enforceAuthProtection($request, 'user_login');
        if ($blocked) {
            return $blocked;
        }

        $payload = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
            'captcha_token' => ['nullable', 'string'],
            'captcha_answer' => ['nullable', 'string'],
            'challenge_id' => ['nullable', 'string'],
            'signup_ticket' => ['nullable', 'string'],
            'website' => ['nullable', 'string'],
            'company_url' => ['nullable', 'string'],
            'fax_number' => ['nullable', 'string'],
        ]);

        $emailNormalized = strtolower(trim($payload['email']));

        $user = User::query()->where('email_normalized', $emailNormalized)->first();
        if (! $user || ! Hash::check($payload['password'], $user->password_hash)) {
            $this->recordAuthFailure($request, 'user_login', 'invalid_credentials');

            return response()->json(['detail' => 'Invalid credentials'], 401);
        }
        if ($user->role !== 'user') {
            $this->recordAuthFailure($request, 'user_login', 'wrong_portal');

            return response()->json([
                'detail' => 'Please use the admin portal to sign in.',
                'code' => 'admin_portal_required',
            ], 403);
        }
        if (! $user->isActiveAccount()) {
            return response()->json([
                'detail' => 'This account has been deactivated. Contact support.',
                'code' => 'account_inactive',
            ], 403);
        }

        $this->clearAuthFailures($request);

        $remember = (bool) ($payload['remember'] ?? false);
        $ttlMinutes = $remember ? $this->jwtTokenService->rememberTtlMinutes() : null;

        $token = $this->jwtTokenService->createForUser($user, $ttlMinutes, $remember);
        $status = $this->subscriptions->statusForUser($user);

        return response()->json([
            'user' => $this->serializeUser($user),
            'subscription' => $status['subscription'],
            'payment_method' => $this->safePaymentMethod($user),
            'requires_plan' => $status['requires_plan'],
            'requires_reactivation' => $status['requires_reactivation'],
            'can_chat' => $status['can_chat'],
            'auto_renew' => false,
            'remember' => $remember,
            'session' => $token,
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $blocked = $this->enforceAuthProtection($request, 'forgot_password');
        if ($blocked) {
            return $blocked;
        }

        $payload = $request->validate([
            'email' => ['required', 'email'],
            'captcha_token' => ['nullable', 'string'],
            'captcha_answer' => ['nullable', 'string'],
            'challenge_id' => ['nullable', 'string'],
            'website' => ['nullable', 'string'],
            'company_url' => ['nullable', 'string'],
            'fax_number' => ['nullable', 'string'],
        ]);

        $emailNormalized = strtolower(trim($payload['email']));
        $generic = [
            'status' => 'ok',
            'message' => 'If an account exists for that email, a reset link has been sent.',
        ];

        $user = User::query()
            ->where('email_normalized', $emailNormalized)
            ->where('role', 'user')
            ->first();

        if (! $user || ! $user->isActiveAccount()) {
            return response()->json($generic);
        }

        $plainToken = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $emailNormalized],
            [
                'token' => Hash::make($plainToken),
                'created_at' => now(),
            ]
        );

        $resetUrl = $this->mailSettings->frontendUrl()
            .'/reset-password?token='.urlencode($plainToken)
            .'&email='.urlencode($emailNormalized);

        try {
            $siteName = $this->systemConfig->get('SITE_NAME') ?: 'NursingAI';
            $this->mailSettings->send(function () use ($emailNormalized, $resetUrl, $siteName): void {
                Mail::to($emailNormalized)->send(new PasswordResetMail($resetUrl, $siteName));
            });
        } catch (Throwable $e) {
            report($e);
            Log::error('auth.forgot_password_mail_failed', [
                'email' => $emailNormalized,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'detail' => 'We could not send the reset email. Check SMTP settings or try again later.',
                'code' => 'mail_send_failed',
            ], 503);
        }

        return response()->json($generic);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $blocked = $this->enforceAuthProtection($request, 'reset_password');
        if ($blocked) {
            return $blocked;
        }

        $payload = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string', 'min:20'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'captcha_token' => ['nullable', 'string'],
            'captcha_answer' => ['nullable', 'string'],
            'challenge_id' => ['nullable', 'string'],
            'website' => ['nullable', 'string'],
            'company_url' => ['nullable', 'string'],
            'fax_number' => ['nullable', 'string'],
        ]);

        $emailNormalized = strtolower(trim($payload['email']));
        $row = DB::table('password_reset_tokens')->where('email', $emailNormalized)->first();

        if (! $row || empty($row->token) || ! Hash::check($payload['token'], (string) $row->token)) {
            $this->recordAuthFailure($request, 'reset_password', 'invalid_token');

            return response()->json([
                'detail' => 'This reset link is invalid or has expired.',
                'code' => 'invalid_reset_token',
            ], 422);
        }

        $createdAt = $row->created_at ? strtotime((string) $row->created_at) : 0;
        if ($createdAt < (time() - 3600)) {
            DB::table('password_reset_tokens')->where('email', $emailNormalized)->delete();

            return response()->json([
                'detail' => 'This reset link is invalid or has expired.',
                'code' => 'invalid_reset_token',
            ], 422);
        }

        $user = User::query()
            ->where('email_normalized', $emailNormalized)
            ->where('role', 'user')
            ->first();

        if (! $user) {
            return response()->json([
                'detail' => 'This reset link is invalid or has expired.',
                'code' => 'invalid_reset_token',
            ], 422);
        }

        $user->password_hash = Hash::make($payload['password']);
        $user->save();

        DB::table('password_reset_tokens')->where('email', $emailNormalized)->delete();
        $this->clearAuthFailures($request);

        return response()->json([
            'status' => 'ok',
            'message' => 'Password updated. You can sign in with your new password.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');
        $status = $this->subscriptions->statusForUser($user);

        return response()->json([
            ...$this->serializeUser($user),
            'subscription' => $status['subscription'],
            'payment_method' => $this->safePaymentMethod($user),
            'requires_plan' => $status['requires_plan'],
            'requires_reactivation' => $status['requires_reactivation'],
            'can_chat' => $status['can_chat'],
            'auto_renew' => false,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');

        $payload = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $user->display_name = trim($payload['display_name']);
        $user->save();

        $status = $this->subscriptions->statusForUser($user);

        return response()->json([
            ...$this->serializeUser($user),
            'subscription' => $status['subscription'],
            'payment_method' => $this->safePaymentMethod($user),
            'requires_plan' => $status['requires_plan'],
            'requires_reactivation' => $status['requires_reactivation'],
            'can_chat' => $status['can_chat'],
            'auto_renew' => false,
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');

        $payload = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($payload['current_password'], $user->password_hash)) {
            return response()->json([
                'detail' => 'Current password is incorrect.',
                'code' => 'invalid_current_password',
            ], 422);
        }

        $user->password_hash = Hash::make($payload['password']);
        $user->save();

        return response()->json([
            'status' => 'ok',
            'message' => 'Password updated successfully.',
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');
        $remember = (bool) $request->attributes->get('jwt_remember', false);
        $ttlMinutes = $remember ? $this->jwtTokenService->rememberTtlMinutes() : null;

        return response()->json([
            'session' => $this->jwtTokenService->createForUser($user, $ttlMinutes, $remember),
        ]);
    }

    private function serializeUser(User $user): array
    {
        $businessClientId = trim((string) ($user->business_client_id ?? ''));
        $businessName = null;
        $workspaceSlug = null;
        $workspaceName = null;

        if (! empty($user->workspace_id)) {
            $workspace = Workspace::query()->find($user->workspace_id);
            $workspaceSlug = $workspace?->workspace_id;
            $workspaceName = $workspace?->name;
            if ($businessClientId === '' && $workspace) {
                $businessClientId = trim((string) ($workspace->business_client_id ?? ''));
            }
        }

        $business = null;
        if ($businessClientId !== '') {
            $business = Business::query()->where('business_client_id', $businessClientId)->first();
        }
        if (! $business && ! empty($user->business_id)) {
            $business = Business::query()->find($user->business_id);
            $businessClientId = trim((string) ($business?->business_client_id ?? $businessClientId));
        }
        $businessName = $business?->name;

        $displayName = trim((string) ($user->display_name ?? ''));
        if ($displayName === '') {
            $displayName = strstr($user->email, '@', true) ?: $user->email;
        }

        return [
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $displayName,
            'role' => $user->role,
            'plan' => $user->plan,
            'business_id' => $user->business_id,
            'business_client_id' => $businessClientId !== '' ? $businessClientId : null,
            'business_name' => $businessName,
            'workspace_id' => $workspaceSlug,
            'workspace_name' => $workspaceName,
            'workspace_internal_id' => $user->workspace_id,
        ];
    }

    private function safePaymentMethod(User $user): ?array
    {
        if (empty($user->stripe_payment_method_id) || ! $this->stripe->isConfigured()) {
            return null;
        }

        try {
            return $this->stripe->paymentMethodForUser($user);
        } catch (Throwable $e) {
            Log::warning('auth.payment_method_lookup_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
