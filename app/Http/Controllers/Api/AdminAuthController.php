<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Services\JwtTokenService;
use App\Services\ProjectApiException;
use App\Services\ProjectApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Throwable;

class AdminAuthController extends Controller
{
    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly ProjectApiService $projectApiService,
    )
    {
    }

    /**
     * Authenticate an admin or user and return an access token with any resolved scope.
     */
    public function login(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $emailNormalized = $this->normalizeEmail($payload['email']);

        $admin = User::query()->where('email_normalized', $emailNormalized)->first();
        if (!$admin || !Hash::check($payload['password'], $admin->password_hash)) {
            return response()->json(['detail' => 'Invalid credentials'], 401);
        }

        if (!in_array($admin->role, ['admin', 'user', 'super_admin'], true)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

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

        if ($admin->role === 'admin' && (!$businessClientId || !$workspaceId)) {
            $ownedBusiness = Business::query()
                ->where('admin_id', $admin->id)
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
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6'],
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
            $projectAdmin = $this->projectApiService->createAdmin([
                'email' => $emailNormalized,
                'password' => $payload['password'],
            ]);

            $externalId = (string) ($projectAdmin['user_id'] ?? '');
            if ($externalId === '') {
                throw new RuntimeException('Project API create-admin did not return a user_id');
            }

            $admin = User::query()->create([
                'external_id' => $externalId,
                'email' => $emailNormalized,
                'email_normalized' => $emailNormalized,
                'password_hash' => $passwordHash,
                'role' => 'admin',
                'business_id' => null,
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
        if (!in_array($admin->role, ['admin', 'super_admin'], true)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $business = Business::query()->where('business_client_id', $payload['business_client_id'])->first();
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if ($admin->role === 'admin' && $business->admin_id && $business->admin_id !== $admin->id) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $workspace = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $payload['workspace_id'])
            ->first();

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
            $projectUser = $this->projectApiService->createUser([
                'email' => $emailNormalized,
                'password' => $payload['password'],
                'business_client_id' => $business->business_client_id,
                'workspace_id' => $workspace->workspace_id,
            ]);

            $externalId = (string) ($projectUser['user_id'] ?? '');
            if ($externalId === '') {
                throw new RuntimeException('Project API create-user did not return a user_id');
            }

            $user = DB::transaction(function () use ($business, $workspace, $emailNormalized, $passwordHash, $externalId): User {
                return User::query()->create([
                    'external_id' => $externalId,
                    'email' => $emailNormalized,
                    'email_normalized' => $emailNormalized,
                    'password_hash' => $passwordHash,
                    'role' => 'user',
                    'business_id' => $business->id,
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

    private function resolveCreateUserError(Throwable $e): string
    {
        if ($e instanceof ProjectApiException) {
            $body = $e->getBody();
            if (is_array($body) && isset($body['detail']) && is_string($body['detail'])) {
                return $body['detail'];
            }

            return $e->getMessage();
        }

        if ($e instanceof RuntimeException) {
            return $e->getMessage();
        }

        return 'Unable to create user';
    }

    private function resolveCreateUserStatus(Throwable $e): int
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
}
