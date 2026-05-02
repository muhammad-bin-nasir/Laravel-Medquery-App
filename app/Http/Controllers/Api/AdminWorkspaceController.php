<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConfig;
use App\Services\ProjectApiException;
use App\Services\ProjectApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminWorkspaceController extends Controller
{
    public function __construct(private readonly ProjectApiService $projectApiService)
    {
    }

    public function create(Request $request, string $business_client_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        $business = $this->resolveBusiness($business_client_id);
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (!$this->canAccessBusiness($admin, $business)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $payload = $request->validate([
            'workspace_id' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $existing = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $payload['workspace_id'])
            ->first();

        $jwtToken = app(\App\Services\JwtTokenService::class)->createForProjectUser($admin)['access_token'];

        if ($existing) {
            try {
                $this->projectApiService
                    ->withToken($jwtToken)
                    ->getWorkspace($business->business_client_id, $payload['workspace_id']);

                return response()->json([
                    'detail' => 'Workspace already exists',
                    'code' => 'workspace_already_exists',
                ], 409);
            } catch (ProjectApiException $e) {
                if ($e->getStatus() !== 404) {
                    throw $e;
                }

                // Local workspace exists but FastAPI does not. Remove stale local row and recreate.
                $existing->delete();
            }
        }

        try {
            $this->projectApiService->withToken($jwtToken)->createWorkspace($business->business_client_id, [
                'workspace_id' => $payload['workspace_id'],
                'name' => $payload['name'],
            ]);
        } catch (ProjectApiException $e) {
            $upstreamDetail = $this->extractUpstreamDetail($e);
            if ($e->getStatus() === 400 && str_contains(strtolower($upstreamDetail), 'workspace already exists')) {
                $workspace = DB::transaction(function () use ($business, $payload): Workspace {
                    $workspace = Workspace::query()->create([
                        'business_client_id' => $business->business_client_id,
                        'workspace_id' => $payload['workspace_id'],
                        'name' => $payload['name'],
                    ]);

                    $defaultConfig = $this->defaultWorkspaceConfigValues();
                    WorkspaceConfig::query()->create([
                        'business_id' => $business->id,
                        'workspace_id' => $workspace->id,
                        ...$defaultConfig,
                    ]);

                    return $workspace;
                });

                return response()->json($this->toWorkspaceOut($workspace), 201);
            }

            return response()->json([
                'detail' => 'Failed to sync workspace to Project backend',
                'code' => 'workspace_sync_failed',
                'errors' => $e->getBody() ?? $e->getMessage(),
            ], $e->getStatus());
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'detail' => 'Failed to sync workspace to Project backend',
                'code' => 'workspace_sync_failed',
            ], 500);
        }

        $workspace = DB::transaction(function () use ($business, $payload): Workspace {
            $workspace = Workspace::query()->create([
                'business_client_id' => $business->business_client_id,
                'workspace_id' => $payload['workspace_id'],
                'name' => $payload['name'],
            ]);

            $defaultConfig = $this->defaultWorkspaceConfigValues();
            WorkspaceConfig::query()->create([
                'business_id' => $business->id,
                'workspace_id' => $workspace->id,
                ...$defaultConfig,
            ]);

            return $workspace;
        });

        return response()->json($this->toWorkspaceOut($workspace), 201);
    }

    public function index(Request $request, string $business_client_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        $business = $this->resolveBusiness($business_client_id);
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (!$this->canAccessBusiness($admin, $business)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $jwtToken = app(\App\Services\JwtTokenService::class)->createForProjectUser($admin)['access_token'];
        $this->syncWorkspacesFromProject($business, $jwtToken);

        $workspaces = Workspace::query()->where('business_client_id', $business->business_client_id)->get();

        return response()->json(
            $workspaces->map(fn (Workspace $workspace): array => $this->toWorkspaceOut($workspace))->values()
        );
    }

    public function show(Request $request, string $business_client_id, string $workspace_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        $business = $this->resolveBusiness($business_client_id);
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (!$this->canAccessBusiness($admin, $business)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $jwtToken = app(\App\Services\JwtTokenService::class)->createForProjectUser($admin)['access_token'];
        $workspace = $this->resolveWorkspace($business, $workspace_id, $jwtToken);

        if (!$workspace) {
            return response()->json(['detail' => 'Workspace not found'], 404);
        }

        return response()->json($this->toWorkspaceOut($workspace));
    }

    public function delete(Request $request, string $business_client_id, string $workspace_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        $business = $this->resolveBusiness($business_client_id);
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (!$this->canAccessBusiness($admin, $business)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $jwtToken = app(\App\Services\JwtTokenService::class)->createForProjectUser($admin)['access_token'];
        $workspace = $this->resolveWorkspace($business, $workspace_id, $jwtToken);

        if (!$workspace) {
            return response()->json(['detail' => 'Workspace not found'], 404);
        }

        try {
            $jwtToken = app(\App\Services\JwtTokenService::class)->createForProjectUser($admin)['access_token'];
            $this->projectApiService
                ->withToken($jwtToken)
                ->deleteWorkspace($business->business_client_id, $workspace->workspace_id);
        } catch (ProjectApiException $e) {
            return response()->json([
                'detail' => 'Failed to sync workspace deletion to Project backend',
                'code' => 'workspace_delete_sync_failed',
                'errors' => $e->getBody() ?? $e->getMessage(),
            ], $e->getStatus());
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'detail' => 'Failed to sync workspace deletion to Project backend',
                'code' => 'workspace_delete_sync_failed',
            ], 500);
        }

        $workspace->delete();

        return response()->json([
            'status' => 'deleted',
            'cascade' => 'documents_and_chunks',
        ]);
    }

    private function resolveBusiness(string $business_client_id): ?Business
    {
        return Business::query()->where('business_client_id', $business_client_id)->first();
    }

    private function resolveWorkspace(Business $business, string $workspace_id, ?string $jwtToken = null): ?Workspace
    {
        $workspace = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $workspace_id)
            ->first();

        if ($workspace) {
            return $workspace;
        }

        $workspace = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('id', $workspace_id)
            ->first();

        if ($workspace || !$jwtToken) {
            return $workspace;
        }

        try {
            $remoteWorkspace = $this->projectApiService
                ->withToken($jwtToken)
                ->getWorkspace($business->business_client_id, $workspace_id);

            return $this->upsertWorkspaceFromProject($business, $remoteWorkspace);
        } catch (ProjectApiException $e) {
            if ($e->getStatus() === 404) {
                return null;
            }

            throw $e;
        }
    }

    private function syncWorkspacesFromProject(Business $business, string $jwtToken): void
    {
        try {
            $remoteWorkspaces = $this->projectApiService
                ->withToken($jwtToken)
                ->listWorkspaces($business->business_client_id);
        } catch (ProjectApiException) {
            return;
        }

        foreach ($remoteWorkspaces as $remoteWorkspace) {
            if (!is_array($remoteWorkspace)) {
                continue;
            }

            $this->upsertWorkspaceFromProject($business, $remoteWorkspace);
        }
    }

    private function upsertWorkspaceFromProject(Business $business, array $remoteWorkspace): Workspace
    {
        $workspaceId = (string) ($remoteWorkspace['workspace_id'] ?? '');
        $name = (string) ($remoteWorkspace['name'] ?? $workspaceId);

        $workspace = Workspace::query()->updateOrCreate(
            [
                'business_client_id' => $business->business_client_id,
                'workspace_id' => $workspaceId,
            ],
            [
                'name' => $name,
            ]
        );

        $config = WorkspaceConfig::query()
            ->where('workspace_id', $workspace->id)
            ->first();

        if (!$config) {
            WorkspaceConfig::query()->create([
                'business_id' => $business->id,
                'workspace_id' => $workspace->id,
                ...$this->defaultWorkspaceConfigValues(),
            ]);
        }

        return $workspace;
    }

    private function canAccessBusiness(User $admin, Business $business): bool
    {
        if ($admin->role === 'super_admin') {
            return true;
        }

        if ($admin->role === 'admin' && $business->admin_id === $admin->id) {
            return true;
        }

        return false;
    }

    private function toWorkspaceOut(Workspace $workspace): array
    {
        return [
            'workspace_id' => $workspace->workspace_id,
            'name' => $workspace->name,
        ];
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

    private function extractUpstreamDetail(ProjectApiException $e): string
    {
        $body = $e->getBody();
        if (is_array($body) && isset($body['detail']) && is_string($body['detail'])) {
            return $body['detail'];
        }

        return $e->getMessage();
    }
}
