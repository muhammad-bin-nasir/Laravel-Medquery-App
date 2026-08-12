<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\SystemConfig;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConfig;
use App\Services\JwtTokenService;
use App\Services\ProjectApiException;
use App\Services\ProjectApiService;
use App\Services\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Throwable;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly ProjectApiService $projectApiService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->attributes->get('admin');
        if (! StaffAccess::isStaff($actor)) {
            return response()->json(['detail' => 'Not allowed.', 'code' => 'forbidden'], 403);
        }

        $role = trim((string) $request->query('role', ''));
        $search = trim((string) $request->query('q', ''));
        $active = $request->query('is_active');

        $query = User::query()->orderByDesc('created_at');

        if ($role !== '') {
            $query->where('role', $role);
        } else {
            // Default: site users + sub-admins the actor can see.
            if ($actor->role === 'sub_admin') {
                $query->where('role', 'user');
            } else {
                $query->whereIn('role', ['user', 'sub_admin']);
            }
        }

        if ($actor->role === 'sub_admin') {
            $ownerId = StaffAccess::scopeOwnerId($actor);
            $businessIds = $ownerId
                ? Business::query()->where('admin_id', $ownerId)->pluck('id')
                : collect();
            $query->where('role', 'user')->whereIn('business_id', $businessIds);
        } elseif ($actor->role === 'admin') {
            $businessIds = Business::query()->where('admin_id', $actor->id)->pluck('id');
            if ($role === 'sub_admin') {
                $query->where('role', 'sub_admin')->where('created_by', $actor->id);
            } elseif ($role === 'user') {
                $query->where('role', 'user')->whereIn('business_id', $businessIds);
            } else {
                $query->where(function ($builder) use ($actor, $businessIds) {
                    $builder
                        ->where(function ($inner) use ($businessIds) {
                            $inner->where('role', 'user')->whereIn('business_id', $businessIds);
                        })
                        ->orWhere(function ($inner) use ($actor) {
                            $inner->where('role', 'sub_admin')->where('created_by', $actor->id);
                        });
                });
            }
        }

        if ($search !== '') {
            $like = '%'.strtolower($search).'%';
            $query->where(function ($builder) use ($like) {
                $builder
                    ->whereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$like]);
            });
        }

        if ($active === '1' || $active === 'true') {
            $query->where('is_active', true);
        } elseif ($active === '0' || $active === 'false') {
            $query->where('is_active', false);
        }

        $users = $query->limit(200)->get()->map(fn (User $user) => $this->serialize($user));

        return response()->json([
            'users' => $users,
            'can_create_sub_admin' => StaffAccess::canCreateSubAdmin($actor),
            'can_create_user' => StaffAccess::canCreateSiteUser($actor),
            'actor_role' => $actor->role,
        ]);
    }

    public function show(Request $request, string $userId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->attributes->get('admin');
        if (! StaffAccess::isStaff($actor)) {
            return response()->json(['detail' => 'Not allowed.', 'code' => 'forbidden'], 403);
        }

        $user = User::query()->find($userId);
        if (! $user || ! $this->canView($actor, $user)) {
            return response()->json(['detail' => 'User not found.', 'code' => 'not_found'], 404);
        }

        return response()->json([
            'user' => $this->serialize($user, true),
            'can_manage' => StaffAccess::canManageTarget($actor, $user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->attributes->get('admin');
        if (! StaffAccess::canCreateSiteUser($actor)) {
            return response()->json(['detail' => 'Not allowed.', 'code' => 'forbidden'], 403);
        }

        $payload = $request->validate([
            'display_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:6'],
            'business_client_id' => ['required', 'string', 'max:100'],
            'workspace_id' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $resolved = $this->resolveBusinessWorkspace($actor, $payload['business_client_id'], $payload['workspace_id']);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$business, $workspace] = $resolved;

        $emailNormalized = $this->normalizeEmail($payload['email']);
        if (User::query()->where('email_normalized', $emailNormalized)->exists()) {
            return response()->json([
                'detail' => 'User already exists.',
                'code' => 'user_already_exists',
            ], 409);
        }

        $passwordHash = Hash::make($payload['password']);

        try {
            $jwtToken = $this->jwtTokenService->createForProjectUser($actor)['access_token'];
            $projectUser = $this->projectApiService->withToken($jwtToken)->createUser([
                'email' => $emailNormalized,
                'password' => $payload['password'],
                'business_client_id' => $business->business_client_id,
                'workspace_id' => $workspace->workspace_id,
            ]);

            $externalId = (string) ($projectUser['user_id'] ?? '');
            if ($externalId === '') {
                throw new RuntimeException('Project API create-user did not return a user_id');
            }

            $user = DB::transaction(function () use ($actor, $business, $workspace, $emailNormalized, $passwordHash, $externalId, $payload): User {
                return User::query()->create([
                    'external_id' => $externalId,
                    'email' => $emailNormalized,
                    'email_normalized' => $emailNormalized,
                    'display_name' => trim((string) ($payload['display_name'] ?? '')) ?: null,
                    'password_hash' => $passwordHash,
                    'role' => 'user',
                    'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
                    'created_by' => $actor->id,
                    'business_id' => $business->id,
                    'business_client_id' => $business->business_client_id,
                    'workspace_id' => $workspace->id,
                ]);
            });
        } catch (Throwable $e) {
            report($e);

            if ($e instanceof ProjectApiException && $e->getStatus() === 409) {
                return response()->json([
                    'detail' => $this->resolveUpstreamError(
                        $e,
                        'This email is already in use for that business.'
                    ),
                    'code' => 'user_already_exists',
                ], 409);
            }

            return response()->json([
                'detail' => $this->resolveUpstreamError($e, 'Unable to create user.'),
                'code' => 'user_sync_failed',
            ], $this->resolveUpstreamStatus($e));
        }

        return response()->json([
            'status' => 'created',
            'user' => $this->serialize($user, true),
        ], 201);
    }

    public function storeSubAdmin(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->attributes->get('admin');
        if (! StaffAccess::canCreateSubAdmin($actor)) {
            return response()->json([
                'detail' => 'Only admins can create sub-admins.',
                'code' => 'forbidden',
            ], 403);
        }

        $payload = $request->validate([
            'display_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:6'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $emailNormalized = $this->normalizeEmail($payload['email']);
        if (User::query()->where('email_normalized', $emailNormalized)->exists()) {
            return response()->json([
                'detail' => 'User already exists.',
                'code' => 'user_already_exists',
            ], 409);
        }

        $passwordHash = Hash::make($payload['password']);

        try {
            $businessClientId = $this->resolveProjectBusinessClientId($actor);
            $jwtToken = $this->jwtTokenService->createForProjectUser($actor)['access_token'];
            $projectAdmin = $this->projectApiService->withToken($jwtToken)->createAdmin([
                'email' => $emailNormalized,
                'password' => $payload['password'],
                'username' => trim((string) ($payload['display_name'] ?? '')) ?: null,
                'business_client_id' => $businessClientId,
                'role' => 'admin',
            ]);

            $externalId = (string) ($projectAdmin['user_id'] ?? '');
            if ($externalId === '') {
                throw new RuntimeException('Project API create-admin did not return a user_id');
            }

            $user = User::query()->create([
                'external_id' => $externalId,
                'email' => $emailNormalized,
                'email_normalized' => $emailNormalized,
                'display_name' => trim((string) ($payload['display_name'] ?? '')) ?: null,
                'password_hash' => $passwordHash,
                'role' => 'sub_admin',
                'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
                'created_by' => $actor->id,
                'business_id' => null,
                'business_client_id' => null,
                'workspace_id' => null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'detail' => $this->resolveUpstreamError($e, 'Unable to create sub-admin.'),
                'code' => 'sub_admin_sync_failed',
            ], $this->resolveUpstreamStatus($e));
        }

        return response()->json([
            'status' => 'created',
            'user' => $this->serialize($user, true),
        ], 201);
    }

    public function update(Request $request, string $userId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->attributes->get('admin');
        if (! StaffAccess::isStaff($actor)) {
            return response()->json(['detail' => 'Not allowed.', 'code' => 'forbidden'], 403);
        }

        $user = User::query()->find($userId);
        if (! $user) {
            return response()->json(['detail' => 'User not found.', 'code' => 'not_found'], 404);
        }

        if (! StaffAccess::canManageTarget($actor, $user)) {
            return response()->json([
                'detail' => 'You cannot update this account.',
                'code' => 'forbidden',
            ], 403);
        }

        // Sub-admins and site-user updates: never allow role elevation through this endpoint.
        $payload = $request->validate([
            'display_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:6'],
            'business_client_id' => ['nullable', 'string', 'max:100'],
            'workspace_id' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($user->role === 'user') {
            $businessClientId = $payload['business_client_id'] ?? $user->business_client_id;
            $workspaceId = $payload['workspace_id'] ?? null;

            if ($workspaceId || isset($payload['business_client_id'])) {
                $workspaceSlug = $workspaceId;
                if (! $workspaceSlug && $user->workspace_id) {
                    $workspaceSlug = Workspace::query()->find($user->workspace_id)?->workspace_id;
                }
                if (! $workspaceSlug) {
                    return response()->json(['detail' => 'Workspace is required.', 'code' => 'validation_error'], 422);
                }

                $resolved = $this->resolveBusinessWorkspace($actor, (string) $businessClientId, (string) $workspaceSlug);
                if ($resolved instanceof JsonResponse) {
                    return $resolved;
                }
                [$business, $workspace] = $resolved;
                $user->business_id = $business->id;
                $user->business_client_id = $business->business_client_id;
                $user->workspace_id = $workspace->id;
            }
        }

        if (array_key_exists('display_name', $payload)) {
            $user->display_name = trim((string) $payload['display_name']) ?: null;
        }

        if (! empty($payload['email'])) {
            $emailNormalized = $this->normalizeEmail($payload['email']);
            $exists = User::query()
                ->where('email_normalized', $emailNormalized)
                ->where('id', '!=', $user->id)
                ->exists();
            if ($exists) {
                return response()->json([
                    'detail' => 'Email is already in use.',
                    'code' => 'user_already_exists',
                ], 409);
            }
            $user->email = $emailNormalized;
            $user->email_normalized = $emailNormalized;
        }

        if (! empty($payload['password'])) {
            $user->password_hash = Hash::make($payload['password']);
        }

        if (array_key_exists('is_active', $payload)) {
            $user->is_active = (bool) $payload['is_active'];
        }

        $user->save();

        return response()->json([
            'status' => 'updated',
            'user' => $this->serialize($user->fresh(), true),
        ]);
    }

    public function setActive(Request $request, string $userId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->attributes->get('admin');
        if (! StaffAccess::isStaff($actor)) {
            return response()->json(['detail' => 'Not allowed.', 'code' => 'forbidden'], 403);
        }

        $payload = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $user = User::query()->find($userId);
        if (! $user) {
            return response()->json(['detail' => 'User not found.', 'code' => 'not_found'], 404);
        }

        if (! StaffAccess::canManageTarget($actor, $user)) {
            return response()->json([
                'detail' => 'You cannot change status for this account.',
                'code' => 'forbidden',
            ], 403);
        }

        $user->is_active = (bool) $payload['is_active'];
        $user->save();

        return response()->json([
            'status' => $user->is_active ? 'activated' : 'deactivated',
            'user' => $this->serialize($user, true),
        ]);
    }

    public function destroy(Request $request, string $userId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->attributes->get('admin');
        if (! StaffAccess::isStaff($actor)) {
            return response()->json(['detail' => 'Not allowed.', 'code' => 'forbidden'], 403);
        }

        $user = User::query()->find($userId);
        if (! $user) {
            return response()->json(['detail' => 'User not found.', 'code' => 'not_found'], 404);
        }

        if (! StaffAccess::canManageTarget($actor, $user)) {
            return response()->json([
                'detail' => 'You cannot delete this account.',
                'code' => 'forbidden',
            ], 403);
        }

        if (! in_array($user->role, ['user', 'sub_admin'], true)) {
            return response()->json([
                'detail' => 'Only site users and sub-admins can be deleted here.',
                'code' => 'forbidden',
            ], 403);
        }

        if ($user->role === 'user' && is_string($user->external_id) && trim($user->external_id) !== '') {
            try {
                $jwtToken = $this->jwtTokenService->createForProjectUser($actor)['access_token'];
                $this->projectApiService->withToken($jwtToken)->deleteUser($user->external_id);
            } catch (Throwable $e) {
                report($e);

                return response()->json([
                    'detail' => $this->resolveUpstreamError($e, 'Unable to delete user. Please try again.'),
                    'code' => 'user_delete_sync_failed',
                ], $this->resolveUpstreamStatus($e));
            }
        }

        $deletedId = $user->id;
        $user->delete();

        return response()->json([
            'status' => 'deleted',
            'user_id' => $deletedId,
        ]);
    }

    private function canView(User $actor, User $target): bool
    {
        if (StaffAccess::isSuper($actor)) {
            return true;
        }

        if ($actor->role === 'admin') {
            if ($target->role === 'sub_admin') {
                return (string) $target->created_by === (string) $actor->id;
            }
            if ($target->role === 'user') {
                return StaffAccess::canManageTarget($actor, $target) || $this->userInOwnedBusiness($actor, $target);
            }

            return false;
        }

        if ($actor->role === 'sub_admin') {
            return $target->role === 'user' && $this->userInOwnedBusiness($actor, $target);
        }

        return false;
    }

    private function userInOwnedBusiness(User $actor, User $target): bool
    {
        $ownerId = StaffAccess::scopeOwnerId($actor);
        if (! $ownerId || ! $target->business_id) {
            return false;
        }
        $business = Business::query()->find($target->business_id);

        return $business && (string) $business->admin_id === (string) $ownerId;
    }

    /**
     * @return array{0: Business, 1: Workspace}|JsonResponse
     */
    private function resolveBusinessWorkspace(User $actor, string $businessClientId, string $workspaceId): array|JsonResponse
    {
        $business = Business::query()->where('business_client_id', $businessClientId)->first();
        if (! $business) {
            return response()->json(['detail' => 'Business not found.', 'code' => 'not_found'], 404);
        }

        if (! StaffAccess::canAccessBusiness($actor, $business)) {
            return response()->json(['detail' => 'Not allowed for this business.', 'code' => 'forbidden'], 403);
        }

        $workspace = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $workspaceId)
            ->first();

        if (! $workspace) {
            $workspace = Workspace::query()
                ->where('business_client_id', $business->business_client_id)
                ->where('id', $workspaceId)
                ->first();
        }

        if (! $workspace) {
            try {
                $jwtToken = $this->jwtTokenService->createForProjectUser($actor)['access_token'];
                $remoteWorkspace = $this->projectApiService
                    ->withToken($jwtToken)
                    ->getWorkspace($business->business_client_id, $workspaceId);

                $workspace = Workspace::query()->updateOrCreate(
                    [
                        'business_id' => $business->id,
                        'workspace_id' => (string) ($remoteWorkspace['workspace_id'] ?? $workspaceId),
                    ],
                    [
                        'business_client_id' => $business->business_client_id,
                        'name' => (string) ($remoteWorkspace['name'] ?? $workspaceId),
                    ]
                );

                if (! WorkspaceConfig::query()->where('workspace_id', $workspace->id)->exists()) {
                    WorkspaceConfig::query()->create([
                        'business_id' => $business->id,
                        'workspace_id' => $workspace->id,
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
                    ]);
                }
            } catch (ProjectApiException $e) {
                if ($e->getStatus() !== 404) {
                    throw $e;
                }
            }
        }

        if (! $workspace) {
            return response()->json(['detail' => 'Workspace not found.', 'code' => 'not_found'], 404);
        }

        return [$business, $workspace];
    }

    private function serialize(User $user, bool $detailed = false): array
    {
        $workspace = $user->workspace_id ? Workspace::query()->find($user->workspace_id) : null;
        $business = $user->business_id
            ? Business::query()->find($user->business_id)
            : ($user->business_client_id
                ? Business::query()->where('business_client_id', $user->business_client_id)->first()
                : null);

        $payload = [
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'role' => $user->role,
            'is_active' => $user->isActiveAccount(),
            'created_by' => $user->created_by,
            'business_client_id' => $user->business_client_id ?: $business?->business_client_id,
            'business_name' => $business?->name,
            'workspace_id' => $workspace?->workspace_id,
            'workspace_name' => $workspace?->name,
            'created_at' => optional($user->created_at)?->toIso8601String(),
            'updated_at' => optional($user->updated_at)?->toIso8601String(),
        ];

        if ($detailed) {
            $payload['external_id'] = $user->external_id;
        }

        return $payload;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function resolveProjectBusinessClientId(User $actor): string
    {
        $ownerId = StaffAccess::scopeOwnerId($actor) ?: $actor->id;
        $owned = Business::query()
            ->where('admin_id', $ownerId)
            ->orderBy('created_at')
            ->first();
        if ($owned?->business_client_id) {
            return (string) $owned->business_client_id;
        }

        if (StaffAccess::isSuper($actor)) {
            $any = Business::query()->orderBy('created_at')->first();
            if ($any?->business_client_id) {
                return (string) $any->business_client_id;
            }
        }

        $configured = trim((string) (SystemConfig::query()
            ->where('key', 'SITE_USER_BUSINESS_CLIENT_ID')
            ->value('value') ?? ''));

        return $configured !== '' ? $configured : 'default';
    }

    private function resolveUpstreamError(Throwable $e, string $fallback): string
    {
        if ($e instanceof ProjectApiException) {
            $body = $e->getBody();
            if (is_array($body) && isset($body['detail']) && is_string($body['detail'])) {
                return $this->sanitizeUserFacingDetail($body['detail'], $fallback);
            }
            if (is_array($body) && isset($body['detail']) && is_array($body['detail'])) {
                $parts = [];
                foreach ($body['detail'] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $msg = trim((string) ($item['msg'] ?? ''));
                    if ($msg !== '') {
                        $parts[] = $msg;
                    }
                }
                if ($parts !== []) {
                    return $this->sanitizeUserFacingDetail(implode('; ', $parts), $fallback);
                }
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

    private function resolveUpstreamStatus(Throwable $e): int
    {
        if ($e instanceof ProjectApiException) {
            return $e->getStatus() ?: 500;
        }

        return 500;
    }
}
