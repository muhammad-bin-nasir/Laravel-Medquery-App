<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Services\JwtTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AdminAuthController extends Controller
{
    public function __construct(private readonly JwtTokenService $jwtTokenService)
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
     * Create a new admin account in the Laravel application.
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

        User::query()->create([
            'email' => $emailNormalized,
            'email_normalized' => $emailNormalized,
            'password_hash' => Hash::make($payload['password']),
            'role' => 'admin',
            'business_id' => null,
            'workspace_id' => null,
        ]);

        return response()->json(['status' => 'created']);
    }

    /**
     * Create a workspace user in Laravel and sync the same account to the FastAPI database.
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

        try {
            $user = DB::transaction(function () use ($business, $workspace, $emailNormalized, $payload): User {
                $user = User::query()->create([
                    'id' => (string) Str::uuid(),
                    'email' => $emailNormalized,
                    'email_normalized' => $emailNormalized,
                    'password_hash' => Hash::make($payload['password']),
                    'role' => 'user',
                    'business_id' => $business->id,
                    'workspace_id' => $workspace->id,
                ]);

                $this->syncUserToFastApi($user, $business, $workspace);

                return $user;
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'detail' => $e instanceof RuntimeException ? $e->getMessage() : 'Unable to create user',
                'code' => 'user_sync_failed',
            ], 500);
        }

        return response()->json([
            'status' => 'created',
            'role' => $user->role,
            'email' => $user->email,
        ]);
    }

    private function syncUserToFastApi(User $user, Business $business, Workspace $workspace): void
    {
        $project = DB::connection('project_pgsql');
        $project->getPdo();

        $projectBusiness = $project->table('businesses')
            ->where('business_client_id', $business->business_client_id)
            ->first();

        if (!$projectBusiness) {
            $project->table('businesses')->insert([
                'id' => $business->id,
                'business_client_id' => $business->business_client_id,
                'admin_id' => null,
                'name' => $business->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $projectBusiness = (object) ['id' => $business->id];
        }

        $projectWorkspace = $project->table('workspaces')
            ->where('business_id', $projectBusiness->id)
            ->where('workspace_id', $workspace->workspace_id)
            ->first();

        if (!$projectWorkspace) {
            $project->table('workspaces')->insert([
                'id' => $workspace->id,
                'business_id' => $projectBusiness->id,
                'workspace_id' => $workspace->workspace_id,
                'name' => $workspace->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $projectWorkspace = (object) ['id' => $workspace->id];
        }

        $project->table('users')->updateOrInsert(
            [
                'business_id' => $projectBusiness->id,
                'email_normalized' => $user->email_normalized,
            ],
            [
                'id' => $user->id,
                'workspace_id' => $projectWorkspace->id,
                'email' => $user->email,
                'password_hash' => $user->password_hash,
                'role' => 'user',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
