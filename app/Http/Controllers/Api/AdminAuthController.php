<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ProtectsPublicAuth;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\SystemConfig;
use App\Models\User;
use App\Models\Workspace;
use App\Services\JwtTokenService;
use App\Services\ProjectApiException;
use App\Services\ProjectApiService;
use App\Services\StaffAccess;
use App\Services\SystemConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AdminAuthController extends Controller
{
    use ProtectsPublicAuth;

    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly ProjectApiService $projectApiService,
        private readonly SystemConfigService $systemConfig,
    )
    {
    }

    /**
     * Public: reports whether the initial admin account still needs to be created, so the
     * frontend can decide whether to show the admin-setup toggle on the signup page. This does
     * NOT gate whether regular users can self-register — that's always allowed.
     */
    public function setupStatus(): JsonResponse
    {
        return response()->json([
            'needs_setup' => User::query()->whereIn('role', ['admin', 'super_admin'])->doesntExist(),
        ]);
    }

    /**
     * Authenticate an admin or user and return an access token with any resolved scope.
     */
    public function login(Request $request): JsonResponse
    {
        $blocked = $this->enforceAuthProtection($request, 'admin_login');
        if ($blocked) {
            return $blocked;
        }

        $payload = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'captcha_token' => ['nullable', 'string'],
            'captcha_answer' => ['nullable', 'string'],
            'challenge_id' => ['nullable', 'string'],
            'website' => ['nullable', 'string'],
            'company_url' => ['nullable', 'string'],
            'fax_number' => ['nullable', 'string'],
        ]);

        $emailNormalized = $this->normalizeEmail($payload['email']);

        $admin = User::query()->where('email_normalized', $emailNormalized)->first();
        if (!$admin || !Hash::check($payload['password'], $admin->password_hash)) {
            $this->recordAuthFailure($request, 'admin_login', 'invalid_credentials');

            return response()->json(['detail' => 'Invalid credentials'], 401);
        }

        if (!StaffAccess::isStaff($admin)) {
            $this->recordAuthFailure($request, 'admin_login', 'wrong_portal');

            return response()->json([
                'detail' => 'Please use the user login page.',
                'code' => 'user_login_required',
            ], 403);
        }

        if (!$admin->isActiveAccount()) {
            return response()->json([
                'detail' => 'This account has been deactivated.',
                'code' => 'account_inactive',
            ], 403);
        }

        $this->clearAuthFailures($request);

        $businessClientId = null;
        $workspaceId = null;

        if ($admin->business_id) {
            $business = Business::query()->find($admin->business_id);
            $businessClientId = $business?->business_client_id;
        }

        if ($admin->workspace_id) {
            $workspace = Workspace::query()->find($admin->workspace_id);
            $workspaceId = $workspace?->workspace_id;
        }

        $scopeOwnerId = StaffAccess::scopeOwnerId($admin);
        if (in_array($admin->role, ['admin', 'sub_admin'], true) && (!$businessClientId || !$workspaceId) && $scopeOwnerId) {
            $ownedBusiness = Business::query()
                ->where('admin_id', $scopeOwnerId)
                ->orderBy('created_at')
                ->first();

            if ($ownedBusiness) {
                $businessClientId = $ownedBusiness->business_client_id;

                if (!$workspaceId) {
                    $ownedWorkspace = Workspace::query()
                        ->where('business_client_id', $ownedBusiness->business_client_id)
                        ->orderBy('created_at')
                        ->first();

                    $workspaceId = $ownedWorkspace?->workspace_id;
                }
            }
        }

        $token = $this->jwtTokenService->createForUser($admin);

        return response()->json([
            'access_token' => $token['access_token'],
            'token_type' => $token['token_type'] ?? 'bearer',
            'expires_in' => $token['expires_in'],
            'expires_at' => $token['expires_at'],
            'business_client_id' => $businessClientId,
            'workspace_id' => $workspaceId,
            'role' => $admin->role,
        ]);
    }

    /**
     * Create a new admin account in FastAPI first, then persist the linked Laravel copy.
     */
    public function createAdmin(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'username' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $emailNormalized = $this->normalizeEmail($payload['email']);

        $existing = User::query()->where('email_normalized', $emailNormalized)->first();
        if ($existing) {
            return response()->json([
                'detail' => 'User already exists',
                'code' => 'user_already_exists',
            ], 409);
        }

        $passwordHash = Hash::make($payload['password']);

        try {
            $businessClientId = trim((string) ($request->input('business_client_id') ?: ''));
            if ($businessClientId === '') {
                $businessClientId = trim((string) (SystemConfig::query()
                    ->where('key', 'SITE_USER_BUSINESS_CLIENT_ID')
                    ->value('value') ?? ''));
            }
            if ($businessClientId === '') {
                $businessClientId = 'default';
            }

            $caller = $request->attributes->get('admin');
            $api = $this->projectApiService;
            if ($caller instanceof User) {
                $jwtToken = $this->jwtTokenService->createForProjectUser($caller)['access_token'];
                $api = $api->withToken($jwtToken);
            }

            $projectAdmin = $api->createAdmin([
                'username' => $payload['username'] ?? null,
                'email' => $emailNormalized,
                'password' => $payload['password'],
                'business_client_id' => $businessClientId,
                'role' => 'admin',
            ]);

            $externalId = (string) ($projectAdmin['user_id'] ?? '');
            if ($externalId === '') {
                throw new RuntimeException('Project API create-admin did not return a user_id');
            }
            $assignedRole = (string) ($projectAdmin['role'] ?? 'admin');
            if (!in_array($assignedRole, ['admin', 'super_admin'], true)) {
                $assignedRole = 'admin';
            }

            $admin = User::query()->create([
                'external_id' => $externalId,
                'email' => $emailNormalized,
                'email_normalized' => $emailNormalized,
                'display_name' => trim((string) ($payload['username'] ?? '')) ?: null,
                'password_hash' => $passwordHash,
                'role' => $assignedRole,
                'is_active' => true,
                'business_id' => null,
                'business_client_id' => null,
                'workspace_id' => null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'detail' => $this->resolveCreateUserError($e),
                'code' => 'admin_sync_failed',
            ], $this->resolveCreateUserStatus($e));
        }

        return response()->json([
            'status' => 'created',
            'role' => $admin->role,
            'email' => $admin->email,
            'external_id' => $admin->external_id,
        ]);
    }

    /**
     * Public self-registration for end users.
     * Calls FastAPI /admin/auth/user-signup, which auto-assigns the user to the default tenant.
     * Then persists the linked Laravel copy.
     */
    public function userSignup(Request $request): JsonResponse
    {
        $blocked = $this->enforceAuthProtection($request, 'user_signup');
        if ($blocked) {
            return $blocked;
        }

        if ($this->systemConfig->isMaintenanceMode()) {
            return response()->json([
                'detail' => $this->systemConfig->maintenanceMessage(),
                'code' => 'maintenance_mode',
            ], 503);
        }

        if (! $this->systemConfig->isUserSignupEnabled()) {
            return response()->json([
                'detail' => 'New user registration is currently disabled.',
                'code' => 'signup_disabled',
            ], 403);
        }

        $payload = $request->validate([
            'username' => ['nullable', 'string', 'max:255'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'captcha_token' => ['nullable', 'string'],
            'captcha_answer' => ['nullable', 'string'],
            'challenge_id' => ['nullable', 'string'],
            'website' => ['nullable', 'string'],
            'company_url' => ['nullable', 'string'],
            'fax_number' => ['nullable', 'string'],
        ]);

        $emailNormalized = $this->normalizeEmail($payload['email']);

        $existing = User::query()->where('email_normalized', $emailNormalized)->first();
        if ($existing) {
            $this->recordAuthFailure($request, 'user_signup', 'user_exists');

            return response()->json([
                'detail' => 'User already exists',
                'code'   => 'user_already_exists',
            ], 409);
        }

        $passwordHash = Hash::make($payload['password']);

        $siteBusinessClientId = trim((string) (SystemConfig::query()->where('key', 'SITE_USER_BUSINESS_CLIENT_ID')->value('value') ?? ''));
        $siteWorkspaceId = trim((string) (SystemConfig::query()->where('key', 'SITE_USER_WORKSPACE_ID')->value('value') ?? ''));

        if ($siteBusinessClientId === '' || $siteWorkspaceId === '') {
            Log::error('signup.site_user_defaults_missing', [
                'reason' => 'SITE_USER_BUSINESS_CLIENT_ID / SITE_USER_WORKSPACE_ID not set in system_config',
            ]);

            return response()->json([
                'detail' => 'Signup is temporarily unavailable. Please try again later.',
                'code' => 'signup_unavailable',
            ], 503);
        }

        $configuredBusiness = Business::query()->where('business_client_id', $siteBusinessClientId)->first();
        if (!$configuredBusiness) {
            Log::error('signup.site_user_business_missing', [
                'business_client_id' => $siteBusinessClientId,
            ]);

            return response()->json([
                'detail' => 'Signup is temporarily unavailable. Please try again later.',
                'code' => 'signup_unavailable',
            ], 503);
        }

        $configuredWorkspace = Workspace::query()
            ->where('business_id', $configuredBusiness->id)
            ->where('workspace_id', $siteWorkspaceId)
            ->first();
        if (!$configuredWorkspace) {
            $configuredWorkspace = Workspace::query()
                ->where('business_client_id', $siteBusinessClientId)
                ->where('workspace_id', $siteWorkspaceId)
                ->first();
        }
        if (!$configuredWorkspace) {
            Log::error('signup.site_user_workspace_missing', [
                'business_client_id' => $siteBusinessClientId,
                'workspace_id' => $siteWorkspaceId,
            ]);

            return response()->json([
                'detail' => 'Signup is temporarily unavailable. Please try again later.',
                'code' => 'signup_unavailable',
            ], 503);
        }

        try {
            $projectUser = $this->projectApiService->userSignup([
                'username' => $payload['username'] ?? null,
                'email' => $emailNormalized,
                'password' => $payload['password'],
                'business_client_id' => $siteBusinessClientId,
                'workspace_id' => $siteWorkspaceId,
            ]);

            $externalId = (string) ($projectUser['user_id'] ?? '');
            $businessClientId = (string) ($projectUser['business_client_id'] ?? $siteBusinessClientId);
            $workspaceSlug = (string) ($projectUser['workspace_id'] ?? $siteWorkspaceId);
            // Public signup is always a site-user flow. Never trust an upstream
            // role value to elevate a public registration.
            $assignedRole = 'user';

            if ($externalId === '') {
                throw new RuntimeException('Project API user-signup did not return a user_id');
            }

            $business = Business::query()->where('business_client_id', $businessClientId)->first() ?: $configuredBusiness;
            $workspace = $business
                ? Workspace::query()
                    ->where('business_id', $business->id)
                    ->where('workspace_id', $workspaceSlug)
                    ->first()
                : null;
            if (!$workspace) {
                $workspace = $configuredWorkspace;
            }

            $user = User::query()->create([
                'external_id' => $externalId,
                'email' => $emailNormalized,
                'email_normalized' => $emailNormalized,
                'display_name' => trim((string) ($payload['username'] ?? '')) ?: null,
                'password_hash' => $passwordHash,
                'role' => $assignedRole,
                'business_id' => $business?->id,
                'business_client_id' => $businessClientId,
                'workspace_id' => $workspace?->id,
            ]);

        } catch (Throwable $e) {
            report($e);
            $this->recordAuthFailure($request, 'user_signup', 'signup_failed');

            [$detail, $status] = $this->resolveSignupFailure($e);

            return response()->json([
                'detail' => $detail,
                'code' => 'signup_failed',
            ], $status);
        }

        $this->clearAuthFailures($request);

        $signupTicket = app(\App\Services\AuthAbuseProtectionService::class)
            ->issueSignupTicket($user->email, (string) $request->ip());

        return response()->json([
            'status' => 'created',
            'role' => $user->role,
            'email' => $user->email,
            'external_id' => $user->external_id,
            'business_client_id' => $businessClientId,
            'workspace_id' => $workspaceSlug,
            'signup_ticket' => $signupTicket,
        ]);
    }

    /**
     * Create a workspace user in FastAPI first, then persist the linked Laravel copy.
     */
    public function createUser(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'email', 'required_without:email'],
            'email' => ['nullable', 'email', 'required_without:username'],
            'password' => ['required', 'string', 'min:6'],
            'business_client_id' => ['required', 'string', 'max:100'],
            'workspace_id' => ['required', 'string', 'max:100'],
        ]);

        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!StaffAccess::canCreateSiteUser($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $business = Business::query()->where('business_client_id', $payload['business_client_id'])->first();
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (!StaffAccess::canAccessBusiness($admin, $business)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $workspace = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $payload['workspace_id'])
            ->first();

        if (!$workspace) {
            $workspace = Workspace::query()
                ->where('business_client_id', $business->business_client_id)
                ->where('id', $payload['workspace_id'])
                ->first();
        }

        if (!$workspace) {
            try {
                $jwtToken = $this->jwtTokenService->createForProjectUser($admin)['access_token'];
                $remoteWorkspace = $this->projectApiService
                    ->withToken($jwtToken)
                    ->getWorkspace($business->business_client_id, $payload['workspace_id']);

                $workspace = Workspace::query()->updateOrCreate(
                    [
                        'business_id' => $business->id,
                        'workspace_id' => (string) ($remoteWorkspace['workspace_id'] ?? $payload['workspace_id']),
                    ],
                    [
                        'business_client_id' => $business->business_client_id,
                        'name' => (string) ($remoteWorkspace['name'] ?? $payload['workspace_id']),
                    ]
                );

                $workspaceConfig = WorkspaceConfig::query()
                    ->where('workspace_id', $workspace->id)
                    ->first();

                if (!$workspaceConfig) {
                    WorkspaceConfig::query()->create([
                        'business_id' => $business->id,
                        'workspace_id' => $workspace->id,
                        ...$this->defaultWorkspaceConfigValues(),
                    ]);
                }
            } catch (ProjectApiException $e) {
                if ($e->getStatus() !== 404) {
                    throw $e;
                }
            }
        }

        if (!$workspace) {
            return response()->json(['detail' => 'Workspace not found'], 404);
        }

        $emailInput = (string) ($payload['username'] ?? $payload['email'] ?? '');
        $emailNormalized = $this->normalizeEmail($emailInput);

        $existing = User::query()
            ->where('business_id', $business->id)
            ->where('email_normalized', $emailNormalized)
            ->first();

        if ($existing) {
            return response()->json([
                'detail' => 'User already exists',
                'code' => 'user_already_exists',
            ], 409);
        }

        $passwordHash = Hash::make($payload['password']);

        try {
            $jwtToken = app(JwtTokenService::class)->createForProjectUser($admin)['access_token'];
            $projectUser = app(ProjectApiService::class)->withToken($jwtToken)->createUser([
                'email' => $emailNormalized,
                'password' => $payload['password'],
                'business_client_id' => $business->business_client_id,
                'workspace_id' => $workspace->workspace_id,
            ]);

            $externalId = (string) ($projectUser['user_id'] ?? '');
            if ($externalId === '') {
                throw new RuntimeException('Project API create-user did not return a user_id');
            }

            $user = DB::transaction(function () use ($admin, $business, $workspace, $emailNormalized, $passwordHash, $externalId): User {
                return User::query()->create([
                    'external_id' => $externalId,
                    'email' => $emailNormalized,
                    'email_normalized' => $emailNormalized,
                    'password_hash' => $passwordHash,
                    'role' => 'user',
                    'is_active' => true,
                    'created_by' => $admin->id,
                    'business_id' => $business->id,
                    'business_client_id' => $business->business_client_id,
                    'workspace_id' => $workspace->id,
                ]);
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'detail' => $this->resolveCreateUserError($e),
                'code' => 'user_sync_failed',
            ], $this->resolveCreateUserStatus($e));
        }

        return response()->json([
            'status' => 'created',
            'role' => $user->role,
            'email' => $user->email,
            'external_id' => $user->external_id,
        ]);
    }

    /**
     * Delete a workspace-scoped user in FastAPI first, then remove the linked Laravel copy.
     */
    public function deleteUser(Request $request, string $user_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!StaffAccess::isStaff($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $user = User::query()->find($user_id);
        if (!$user) {
            return response()->json(['detail' => 'User not found'], 404);
        }

        if (!StaffAccess::canManageTarget($admin, $user) || $user->role !== 'user') {
            return response()->json(['detail' => 'Only workspace users can be deleted'], 403);
        }

        if (!is_string($user->external_id) || trim($user->external_id) === '') {
            return response()->json([
                'detail' => 'User account is incomplete and cannot be deleted.',
                'code' => 'user_sync_failed',
            ], 500);
        }

        try {
            $jwtToken = $this->jwtTokenService->createForProjectUser($admin)['access_token'];
            $this->projectApiService->withToken($jwtToken)->deleteUser($user->external_id);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'detail' => $this->resolveDeleteUserError($e),
                'code' => 'user_delete_sync_failed',
            ], $this->resolveDeleteUserStatus($e));
        }

        $user->delete();

        return response()->json([
            'status' => 'deleted',
            'user_id' => $user_id,
        ]);
    }

    /**
     * Public signup is unauthenticated, so upstream plumbing details must never reach the
     * visitor. Only reasons a visitor can act on are translated; everything else is generic.
     *
     * @return array{0: string, 1: int}
     */
    private function resolveSignupFailure(Throwable $e): array
    {
        if ($e instanceof ProjectApiException) {
            $status = $e->getStatus();

            if ($status === 409) {
                return ['An account with this email already exists.', 409];
            }

            if ($status === 422 || $status === 400) {
                return ['Please enter a valid email address and a password of at least 6 characters.', 422];
            }
        }

        return ['Signup is temporarily unavailable. Please try again later.', 503];
    }

    private function resolveCreateUserError(Throwable $e): string
    {
        $fallback = 'Unable to create user. Please try again.';

        if ($e instanceof ProjectApiException) {
            $body = $e->getBody();
            if (is_array($body) && isset($body['detail']) && is_string($body['detail'])) {
                return $this->sanitizeUserFacingDetail($body['detail'], $fallback);
            }

            return $fallback;
        }

        if ($e instanceof RuntimeException) {
            return $this->sanitizeUserFacingDetail($e->getMessage(), $fallback);
        }

        return $fallback;
    }

    private function resolveCreateUserStatus(Throwable $e): int
    {
        if ($e instanceof ProjectApiException) {
            return $e->getStatus();
        }

        return 500;
    }

    private function resolveDeleteUserError(Throwable $e): string
    {
        $fallback = 'Unable to delete user. Please try again.';

        if ($e instanceof ProjectApiException) {
            $body = $e->getBody();
            if (is_array($body) && isset($body['detail']) && is_string($body['detail'])) {
                return $this->sanitizeUserFacingDetail($body['detail'], $fallback);
            }

            return $fallback;
        }

        if ($e instanceof RuntimeException) {
            return $this->sanitizeUserFacingDetail($e->getMessage(), $fallback);
        }

        return $fallback;
    }

    private function sanitizeUserFacingDetail(string $detail, string $fallback): string
    {
        $detail = trim($detail);
        if ($detail === '') {
            return $fallback;
        }

        if (preg_match('/Project API|Project backend|FastAPI|Laravel|\/admin\/auth|returned \d{3}/i', $detail)) {
            return $fallback;
        }

        return $detail;
    }

    private function resolveDeleteUserStatus(Throwable $e): int
    {
        if ($e instanceof ProjectApiException) {
            return $e->getStatus();
        }

        return 500;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function defaultWorkspaceConfigValues(): array
    {
        return [
            'chunk_words' => 300,
            'overlap_words' => 50,
            'top_k' => 5,
            'similarity_threshold' => 0.2,
            'max_context_chars' => 12000,
            'embedding_model' => 'text-embedding-3-small',
            'use_local_embeddings' => false,
            'chat_model_default' => 'gpt-4.1-mini',
            'chat_temperature_default' => 0.2,
            'chat_max_tokens_default' => 600,
            'prompt_engineering' => 'You are a medical assistant. Provide concise answers based on the context.',
        ];
    }
}
