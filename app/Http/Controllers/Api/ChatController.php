<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ChatHeader;
use App\Models\ChatRequest;
use App\Models\ChatResponse;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConfig;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chatService)
    {
    }

    public function deleteChatHeader(Request $request, string $chat_id): JsonResponse
    {
        $admin = $this->admin($request);

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

        $requestIds = ChatRequest::query()
            ->where('business_id', $header->business_id)
            ->where('workspace_id', $header->workspace_id)
            ->where('chat_header', $chat_id)
            ->where(function ($q) use ($admin): void {
                $q->where('user_uuid', $admin->id)
                    ->orWhere(function ($nested) use ($admin): void {
                        $nested->whereNull('user_uuid')
                            ->where('user_id', $admin->email);
                    });
            })
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($header, $requestIds): void {
            if (!empty($requestIds)) {
                ChatResponse::query()->whereIn('request_id', $requestIds)->delete();
                ChatRequest::query()->whereIn('id', $requestIds)->delete();
            }
            $header->delete();
        });

        return response()->json(['status' => 'deleted', 'chat_id' => $chat_id]);
    }

    public function getMyChatHeaders(Request $request): JsonResponse
    {
        $admin = $this->admin($request);

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

    public function getUserChatHistory(Request $request, string $user_id): JsonResponse
    {
        $admin = $this->admin($request);
        if (!in_array($admin->role, ['admin', 'super_admin'], true)) {
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
        $admin = $this->admin($request);
        $payload = $this->validatedPayload($request);

        [$business, $workspace, $config] = $this->resolveScope($admin, $payload);

        $result = $this->chatService->generateResponse(
            admin: $admin,
            businessId: $business->id,
            workspaceId: $workspace->id,
            config: $config,
            payload: $payload,
        );

        return response()->json([
            'business_client_id' => $payload['business_client_id'],
            'workspace_id' => $payload['workspace_id'],
            'user_id' => $admin->email,
            'query' => $payload['query'],
            'answer' => $result['answer'],
            'sources' => $result['sources'],
            'usage' => $result['usage'],
        ]);
    }

    public function streamChat(Request $request): StreamedResponse|JsonResponse
    {
        $admin = $this->admin($request);
        $payload = $this->validatedPayload($request);

        [$business, $workspace, $config] = $this->resolveScope($admin, $payload);

        $result = $this->chatService->generateResponse(
            admin: $admin,
            businessId: $business->id,
            workspaceId: $workspace->id,
            config: $config,
            payload: $payload,
        );

        return response()->stream(function () use ($result): void {
            echo 'data: '.json_encode(['type' => 'start'], JSON_UNESCAPED_UNICODE)."\n\n";
            @ob_flush();
            flush();

            foreach (preg_split('/(\s+)/u', $result['answer'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
                echo 'data: '.json_encode(['token' => $piece], JSON_UNESCAPED_UNICODE)."\n\n";
                @ob_flush();
                flush();
                usleep(20000);
            }

            echo 'data: '.json_encode(['type' => 'done', 'sources' => $result['sources'], 'usage' => $result['usage']], JSON_UNESCAPED_UNICODE)."\n\n";
            @ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
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

        if (!in_array($admin->role, ['admin', 'super_admin'], true)) {
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
}
