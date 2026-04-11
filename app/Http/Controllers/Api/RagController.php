<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConfig;
use App\Services\RagRetrievalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RagController extends Controller
{
    public function __construct(private readonly RagRetrievalService $ragRetrievalService)
    {
    }

    public function retrieve(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'business_client_id' => ['required', 'string', 'max:100'],
            'workspace_id' => ['required', 'string', 'max:100'],
            'user_id' => ['required', 'string', 'max:255'],
            'query' => ['required', 'string'],
            'top_k' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        $business = Business::query()->where('business_client_id', $payload['business_client_id'])->first();
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if ($admin->role === 'admin' && $business->admin_id !== $admin->id) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $workspace = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $payload['workspace_id'])
            ->first();

        if (!$workspace) {
            return response()->json(['detail' => 'Workspace not found'], 404);
        }

        if (!$this->ensureRagAccess($admin, $business->id, $workspace->id)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $config = WorkspaceConfig::query()->where('workspace_id', $workspace->id)->first();
        $topK = (int) ($payload['top_k'] ?? ($config?->top_k ?? 7));
        $threshold = (float) ($config?->similarity_threshold ?? 0.0);

        $queryText = trim((string) $payload['query']);
        $retrieved = $this->ragRetrievalService->retrieveChunks(
            businessId: $business->id,
            workspaceId: $workspace->id,
            query: $queryText,
            topK: $topK,
            threshold: $threshold,
        );

        $retrievedChunks = collect($retrieved)->map(function (array $item): array {
            $chunk = $item['chunk'];

            return [
                'chunk_id' => (string) $chunk->id,
                'document_id' => (string) $chunk->document_id,
                'filename' => (string) $item['filename'],
                'page' => $chunk->page_number,
                'score' => (float) $item['score'],
                'content' => (string) $chunk->content,
            ];
        })->values();

        return response()->json([
            'business_client_id' => $payload['business_client_id'],
            'workspace_id' => $payload['workspace_id'],
            'user_id' => $payload['user_id'],
            'query' => $queryText,
            'retrieved_chunks' => $retrievedChunks,
        ]);
    }

    private function ensureRagAccess(User $admin, string $businessId, string $workspaceId): bool
    {
        if ($admin->role === 'super_admin') {
            return true;
        }

        if ($admin->role === 'admin' && $admin->business_id === $businessId) {
            return true;
        }

        if ($admin->role === 'user' && $admin->business_id === $businessId && $admin->workspace_id === $workspaceId) {
            return true;
        }

        return false;
    }

}
