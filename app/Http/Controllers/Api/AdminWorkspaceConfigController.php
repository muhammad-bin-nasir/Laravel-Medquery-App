<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWorkspaceConfigController extends Controller
{
    public function show(Request $request, string $business_client_id, string $workspace_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        [$business, $workspace] = $this->resolveWorkspace($business_client_id, $workspace_id);
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (!$workspace) {
            return response()->json(['detail' => 'Workspace not found'], 404);
        }

        if (!$this->canAccess($admin, $business, $workspace)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $config = WorkspaceConfig::query()->where('workspace_id', $workspace->id)->first();
        if (!$config) {
            return response()->json(['detail' => 'Config not found'], 404);
        }

        return response()->json($this->toConfigOut($config));
    }

    public function update(Request $request, string $business_client_id, string $workspace_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        [$business, $workspace] = $this->resolveWorkspace($business_client_id, $workspace_id);
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (!$workspace) {
            return response()->json(['detail' => 'Workspace not found'], 404);
        }

        if (!$this->canAccess($admin, $business, $workspace)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $validated = $request->validate([
            'chunk_words' => ['sometimes', 'integer'],
            'overlap_words' => ['sometimes', 'integer'],
            'top_k' => ['sometimes', 'integer'],
            'similarity_threshold' => ['sometimes', 'numeric'],
            'max_context_chars' => ['sometimes', 'integer'],
            'embedding_model' => ['sometimes', 'string', 'max:255'],
            'use_local_embeddings' => ['sometimes', 'boolean'],
            'chat_model_default' => ['sometimes', 'string', 'max:255'],
            'chat_temperature_default' => ['sometimes', 'numeric'],
            'chat_max_tokens_default' => ['sometimes', 'integer'],
            'prompt_engineering' => ['sometimes', 'string'],
        ]);

        $payload = array_replace($this->defaultConfigValues(), $validated);

        $config = WorkspaceConfig::query()->where('workspace_id', $workspace->id)->first();
        if (!$config) {
            $config = WorkspaceConfig::query()->create([
                'business_id' => $business->id,
                'workspace_id' => $workspace->id,
                ...$payload,
            ]);
        } else {
            $config->fill($payload);
            $config->save();
        }

        return response()->json($this->toConfigOut($config));
    }

    private function resolveWorkspace(string $business_client_id, string $workspace_id): array
    {
        $business = Business::query()->where('business_client_id', $business_client_id)->first();
        if (!$business) {
            return [null, null];
        }

        $workspace = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $workspace_id)
            ->first();

        return [$business, $workspace];
    }

    private function canAccess(User $admin, Business $business, Workspace $workspace): bool
    {
        if ($admin->role === 'super_admin') {
            return true;
        }

        if ($admin->role === 'admin' && $business->admin_id === $admin->id) {
            return true;
        }

        if (
            $admin->role === 'user'
            && $admin->business_id === $business->id
            && $admin->workspace_id === $workspace->id
        ) {
            return true;
        }

        return false;
    }

    private function defaultConfigValues(): array
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

    private function toConfigOut(WorkspaceConfig $config): array
    {
        return [
            'chunk_words' => (int) $config->chunk_words,
            'overlap_words' => (int) $config->overlap_words,
            'top_k' => (int) $config->top_k,
            'similarity_threshold' => (float) $config->similarity_threshold,
            'max_context_chars' => (int) $config->max_context_chars,
            'embedding_model' => (string) $config->embedding_model,
            'use_local_embeddings' => (bool) $config->use_local_embeddings,
            'chat_model_default' => (string) $config->chat_model_default,
            'chat_temperature_default' => (float) $config->chat_temperature_default,
            'chat_max_tokens_default' => (int) $config->chat_max_tokens_default,
            'prompt_engineering' => (string) $config->prompt_engineering,
        ];
    }
}
