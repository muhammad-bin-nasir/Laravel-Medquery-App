<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ChatHeader;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConfig;
use App\Services\ProjectApiException;
use App\Services\ProjectApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    public function __construct(private readonly ProjectApiService $projectApiService)
    {
    }

    public function deleteChatHeader(Request $request, string $chat_id): JsonResponse
    {
        $admin = $this->admin($request);
        try {
            $jwtToken = app(
                \App\Services\JwtTokenService::class
            )->createForProjectUser($admin)['access_token'];

            $this->projectApiService
                ->withToken($jwtToken)
                ->deleteChatHeader($chat_id);
        } catch (ProjectApiException $e) {
            if ($e->getStatus() !== 404) {
                return response()->json([
                    'detail' => 'Unable to delete chat. Please try again.',
                    'code' => 'chat_delete_sync_failed',
                ], $e->getStatus());
            }
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'detail' => 'Unable to delete chat. Please try again.',
                'code' => 'chat_delete_sync_failed',
            ], 500);
        }

        $header = ChatHeader::query()
            ->where('chat_id', $chat_id)
            ->where(function ($q) use ($admin): void {
                $q->where('owner_user_uuid', $admin->id)
                    ->orWhere(function ($nested) use ($admin): void {
                        $nested->whereNull('owner_user_uuid')
                            ->where('owner_user_id', $admin->email);
                    });
            })
            ->first();

        if (!$header) {
            return response()->json(['detail' => 'Chat header not found'], 404);
        }

        DB::transaction(function () use ($header): void {
            $header->delete();
        });

        return response()->json(['status' => 'deleted', 'chat_id' => $chat_id, 'soft_deleted' => true]);
    }

    public function renameChatHeader(Request $request, string $chat_id): JsonResponse
    {
        $admin = $this->admin($request);
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:80'],
        ]);
        $title = trim((string) $validated['title']);

        try {
            $jwtToken = app(\App\Services\JwtTokenService::class)->createForProjectUser($admin)['access_token'];

            $remote = $this->projectApiService
                ->withToken($jwtToken)
                ->renameChatHeader($chat_id, ['title' => $title]);

            if (is_array($remote) && ! empty($remote['title'])) {
                $title = trim((string) $remote['title']);
            }
        } catch (ProjectApiException $e) {
            if ($e->getStatus() !== 404) {
                return response()->json([
                    'detail' => 'Unable to rename chat. Please try again.',
                    'code' => 'chat_rename_sync_failed',
                ], $e->getStatus());
            }
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'detail' => 'Unable to rename chat. Please try again.',
                'code' => 'chat_rename_sync_failed',
            ], 500);
        }

        $header = ChatHeader::query()
            ->where('chat_id', $chat_id)
            ->where(function ($q) use ($admin): void {
                $q->where('owner_user_uuid', $admin->id)
                    ->orWhere('owner_user_id', $admin->email)
                    ->orWhere(function ($nested) use ($admin): void {
                        $nested->whereNull('owner_user_uuid')
                            ->where('owner_user_id', $admin->email);
                    });
            })
            ->first();

        if (! $header) {
            $header = ChatHeader::query()->where('chat_id', $chat_id)->first();
        }

        if (! $header) {
            return response()->json(['detail' => 'Chat header not found'], 404);
        }

        $header->title = Str::limit($title, 80, '');
        $header->owner_user_id = strtolower(trim((string) $admin->email));
        $header->owner_user_uuid = $admin->id;
        $header->save();

        return response()->json([
            'status' => 'renamed',
            'chat_id' => $chat_id,
            'title' => $header->title,
        ]);
    }

    public function getChatThread(Request $request, string $chat_id): JsonResponse
    {
        $admin = $this->admin($request);

        try {
            $jwtToken = app(\App\Services\JwtTokenService::class)->createForProjectUser($admin)['access_token'];

            $thread = $this->projectApiService
                ->withToken($jwtToken)
                ->getChatThread($chat_id);

            return response()->json($thread);
        } catch (ProjectApiException $e) {
            return response()->json([
                'detail' => 'Unable to load chat. Please try again.',
                'code' => 'chat_thread_load_failed',
            ], $e->getStatus());
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'detail' => 'Unable to load chat. Please try again.',
                'code' => 'chat_thread_load_failed',
            ], 500);
        }
    }

    public function getMyChatHeaders(Request $request): JsonResponse
    {
        $admin = $this->admin($request);

        $this->syncChatHeadersFromProject($admin);

        $headers = ChatHeader::query()
            ->where(function ($q) use ($admin): void {
                $q->where('owner_user_uuid', $admin->id)
                    ->orWhere(function ($nested) use ($admin): void {
                        $nested->whereNull('owner_user_uuid')
                            ->where('owner_user_id', $admin->email);
                    });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'user_id' => $admin->email,
            'count' => $headers->count(),
            'chats' => $headers->map(fn (ChatHeader $header): array => [
                'chat_id' => $header->chat_id,
                'title' => $header->title,
                'user_id' => $header->owner_user_id ?? '',
                'created_at' => optional($header->created_at)?->toISOString(),
                'updated_at' => optional($header->updated_at)?->toISOString(),
            ])->values(),
        ]);
    }

    private function syncChatHeadersFromProject(User $admin): void
    {
        try {
            $jwtToken = app(
                \App\Services\JwtTokenService::class
            )->createForProjectUser($admin)['access_token'];

            $remoteHeaders = $this->projectApiService
                ->withToken($jwtToken)
                ->getMyChatHeaders();
        } catch (ProjectApiException) {
            return;
        } catch (\Throwable $throwable) {
            report($throwable);
            return;
        }

        $remoteChats = is_array($remoteHeaders['chats'] ?? null)
            ? $remoteHeaders['chats']
            : [];

        foreach ($remoteChats as $chat) {
            if (!is_array($chat)) {
                continue;
            }

            $chatId = trim((string) ($chat['chat_id'] ?? ''));
            if ($chatId === '') {
                continue;
            }

            $title = trim((string) ($chat['title'] ?? ''));
            if ($title === '') {
                $title = 'New chat';
            }

            ChatHeader::withTrashed()->updateOrCreate(
                [
                    'owner_user_id' => $admin->email,
                    'chat_id' => $chatId,
                ],
                [
                    'owner_user_uuid' => $admin->id,
                    'title' => Str::limit($title, 80, ''),
                    'deleted_at' => null,
                ]
            );
        }
    }

    public function createChatHeader(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        $payload = $request->validate([
            'business_client_id' => ['required', 'string', 'max:100'],
            'workspace_id' => ['required', 'string', 'max:100'],
            'user_id' => ['required', 'string', 'max:255'],
            'chat_id' => ['required', 'string', 'max:255'],
            'chat_title' => ['nullable', 'string', 'max:255'],
        ]);

        $payload['business_client_id'] = trim((string) $payload['business_client_id']);
        $payload['workspace_id'] = trim((string) $payload['workspace_id']);
        $payload['user_id'] = strtolower(trim((string) $payload['user_id']));
        $payload['chat_id'] = trim((string) $payload['chat_id']);

        try {
            $jwtToken = app(\App\Services\JwtTokenService::class)->createForProjectUser($admin)['access_token'];
            $header = $this->projectApiService
                ->withToken($jwtToken)
                ->createChatHeader($payload);
        } catch (ProjectApiException $e) {
            return response()->json([
                'detail' => 'Unable to save chat. Please try again.',
                'code' => 'chat_header_sync_failed',
            ], $e->getStatus());
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'detail' => 'Unable to save chat. Please try again.',
                'code' => 'chat_header_sync_failed',
            ], 500);
        }

        return response()->json($header, 201);
    }

    public function getUserChatHistory(Request $request, string $user_id): JsonResponse
    {
        $admin = $this->admin($request);
        if (!in_array($admin->role, ['admin', 'super_admin', 'sub_admin'], true)) {
            return response()->json(['detail' => 'Admin required'], 403);
        }

        $requestedOwner = strtolower(trim($user_id));
        $headers = ChatHeader::query()
            ->where('owner_user_id', $requestedOwner)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'user_id' => $requestedOwner,
            'count' => $headers->count(),
            'chats' => $headers->map(fn (ChatHeader $header): array => [
                'chat_id' => $header->chat_id,
                'title' => $header->title,
                'user_id' => $header->owner_user_id ?? '',
                'created_at' => optional($header->created_at)?->toISOString(),
                'updated_at' => optional($header->updated_at)?->toISOString(),
            ])->values(),
        ]);
    }

    public function generateChat(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request);

        try {
            $result = $this->projectApiService->chatGenerate([
                'business_client_id' => $payload['business_client_id'],
                'workspace_id' => $payload['workspace_id'],
                'user_id' => $payload['user_id'],
                'query' => $payload['query'],
                'chat_id' => $payload['chat_id'] ?? null,
                'chat_title' => $payload['chat_title'] ?? null,
                'prompt_engineering' => $payload['prompt_engineering'] ?? null,
                'chat_config_override' => $payload['chat_config_override'] ?? null,
            ]);

            return response()->json($result);
        } catch (ProjectApiException $e) {
            $body = $e->getBody();

            if (is_array($body)) {
                return response()->json($body, $e->getStatus());
            }

            return response()->json([
                'detail' => $e->getMessage(),
            ], $e->getStatus());
        }
    }

    public function streamChat(Request $request): StreamedResponse|JsonResponse
    {
        $payload = $this->validatedPayload($request);

        try {
            return $this->projectApiService->streamChat([
                'business_client_id' => $payload['business_client_id'],
                'workspace_id' => $payload['workspace_id'],
                'user_id' => $payload['user_id'],
                'query' => $payload['query'],
                'chat_id' => $payload['chat_id'] ?? null,
                'chat_title' => $payload['chat_title'] ?? null,
                'prompt_engineering' => $payload['prompt_engineering'] ?? null,
                'chat_config_override' => $payload['chat_config_override'] ?? null,
            ]);
        } catch (ProjectApiException $e) {
            $body = $e->getBody();

            if (is_array($body)) {
                return response()->json($body, $e->getStatus());
            }

            return response()->json([
                'detail' => $e->getMessage(),
            ], $e->getStatus());
        }
    }

    public function testStream(): StreamedResponse
    {
        return response()->stream(function (): void {
            for ($i = 0; $i < 10; $i++) {
                echo 'data: '.json_encode(['token' => 'TOKEN_'.$i, 'num' => $i], JSON_UNESCAPED_UNICODE)."\n\n";
                @ob_flush();
                flush();
                usleep(100000);
            }
            echo 'data: '.json_encode(['type' => 'done'], JSON_UNESCAPED_UNICODE)."\n\n";
            @ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function admin(Request $request): User
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        return $admin;
    }

    private function validatedPayload(Request $request): array
    {
        $payload = $request->validate([
            'business_client_id' => ['required', 'string', 'max:100'],
            'workspace_id' => ['required', 'string', 'max:100'],
            'user_id' => ['required', 'string', 'max:255'],
            'query' => ['required', 'string'],
            'chat_id' => ['nullable', 'string', 'max:255'],
            'chat_title' => ['nullable', 'string', 'max:255'],
            'prompt_engineering' => ['nullable', 'string'],
            'chat_config_override' => ['nullable', 'array'],
            'chat_config_override.model' => ['nullable', 'string', 'max:255'],
            'chat_config_override.temperature' => ['nullable', 'numeric'],
            'chat_config_override.max_tokens' => ['nullable', 'integer'],
        ]);

        $payload['business_client_id'] = trim((string) $payload['business_client_id']);
        $payload['workspace_id'] = trim((string) $payload['workspace_id']);
        $payload['user_id'] = strtolower(trim((string) $payload['user_id']));

        return $payload;
    }

    private function resolveScope(User $admin, array $payload): array
    {
        $business = Business::query()->where('business_client_id', $payload['business_client_id'])->first();
        if (!$business) {
            throw new HttpException(404, 'Business not found');
        }

        if ($admin->role === 'admin' && $business->admin_id && $business->admin_id !== $admin->id) {
            throw new HttpException(403, 'Not allowed');
        }

        $workspace = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $payload['workspace_id'])
            ->first();

        if (!$workspace) {
            throw new HttpException(
                404,
                sprintf(
                    'Workspace not found for business_client_id "%s" and workspace_id "%s"',
                    $business->business_client_id,
                    $payload['workspace_id']
                )
            );
        }

        if (!in_array($admin->role, ['admin', 'super_admin', 'sub_admin'], true)) {
            if ($admin->role !== 'user' || $admin->business_id !== $business->id || $admin->workspace_id !== $workspace->id) {
                throw new HttpException(403, 'Not allowed for this business/workspace');
            }
        }

        $config = WorkspaceConfig::query()->where('workspace_id', $workspace->id)->first();
        if (!$config) {
            throw new HttpException(404, 'Workspace config not found. Create or seed config for this workspace.');
        }

        return [$business, $workspace, $config];
    }

    private function resolveHeaderScope(User $admin, array $payload): array
    {
        $business = Business::query()->where('business_client_id', $payload['business_client_id'])->first();
        if (!$business) {
            throw new HttpException(404, 'Business not found');
        }

        if ($admin->role === 'admin' && $business->admin_id && $business->admin_id !== $admin->id) {
            throw new HttpException(403, 'Not allowed');
        }

        $workspace = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $payload['workspace_id'])
            ->first();

        if (!$workspace) {
            throw new HttpException(
                404,
                sprintf(
                    'Workspace not found for business_client_id "%s" and workspace_id "%s"',
                    $business->business_client_id,
                    $payload['workspace_id']
                )
            );
        }

        return [$business, $workspace];
    }
}
