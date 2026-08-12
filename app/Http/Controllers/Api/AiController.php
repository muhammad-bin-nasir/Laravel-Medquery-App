<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FastApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatRequest;
use App\Http\Requests\AiRetrieveRequest;
use App\Models\ChatHeader;
use App\Models\User;
use App\Services\Ai\ChatGateway;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AiController extends Controller
{
    public function __construct(
        private readonly ChatGateway $chatGateway,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    public function chat(AiChatRequest $request): JsonResponse
    {
        $correlationId = (string) $request->attributes->get('correlation_id', '');

        try {
            $payload = $request->validated();
            $user = $request->user() ?: $request->attributes->get('admin');
            if ($user && ! empty($user->email)) {
                $payload['user_id'] = strtolower(trim((string) $user->email));
            }
            $response = $this->chatGateway->chat($payload, $correlationId);
            $this->syncLocalChatHeader($request, $payload, $response);
            $this->recordUsage($user, $response);

            return response()->json($response);
        } catch (FastApiException $e) {
            return $this->upstreamError($e, $correlationId);
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                500,
                'ai_gateway_error',
                'Unexpected error while handling AI chat request.',
                ['exception' => $e->getMessage()],
                $correlationId,
            );
        }
    }

    public function stream(AiChatRequest $request): StreamedResponse|JsonResponse
    {
        $correlationId = (string) $request->attributes->get('correlation_id', '');

        try {
            return $this->chatGateway->stream($request->validated(), $correlationId);
        } catch (FastApiException $e) {
            return $this->upstreamError($e, $correlationId);
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                500,
                'ai_gateway_error',
                'Unexpected error while starting AI chat stream.',
                ['exception' => $e->getMessage()],
                $correlationId,
            );
        }
    }

    public function retrieve(AiRetrieveRequest $request): JsonResponse
    {
        $correlationId = (string) $request->attributes->get('correlation_id', '');

        try {
            return response()->json($this->chatGateway->retrieve($request->validated(), $correlationId));
        } catch (FastApiException $e) {
            return $this->upstreamError($e, $correlationId);
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                500,
                'ai_gateway_error',
                'Unexpected error while handling AI retrieve request.',
                ['exception' => $e->getMessage()],
                $correlationId,
            );
        }
    }

    public function voice(Request $request): JsonResponse
    {
        $correlationId = (string) $request->attributes->get('correlation_id', '');

        $validator = Validator::make($request->all(), [
            'business_client_id' => ['required', 'string', 'max:255'],
            'workspace_id' => ['required', 'string', 'max:255'],
            'user_id' => ['required', 'string', 'max:255'],
            'chat_id' => ['nullable', 'string', 'max:255'],
            'chat_title' => ['nullable', 'string', 'max:255'],
            'prompt_engineering' => ['nullable', 'string', 'max:4000'],
            'audio_file' => ['required', 'file', 'mimes:webm,wav,mp3,mp4,m4a,ogg', 'max:15360'],
        ]);

        if ($validator->fails()) {
            return $this->error(
                422,
                'validation_error',
                'The given data was invalid.',
                ['errors' => $validator->errors()->toArray()],
                $correlationId,
            );
        }

        try {
            $validated = $validator->validated();
            $user = $request->user();
            if ($user && ! empty($user->email)) {
                $validated['user_id'] = strtolower(trim((string) $user->email));
            }
            $audioFile = $request->file('audio_file');

            $response = $this->chatGateway->voice($validated, $audioFile, $correlationId);
            $this->syncLocalChatHeader($request, $validated, $response);
            $this->recordUsage($user ?: $request->attributes->get('admin'), $response);

            return response()->json($response);
        } catch (FastApiException $e) {
            return $this->upstreamError($e, $correlationId);
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                500,
                'ai_gateway_error',
                'Unexpected error while handling AI voice chat request.',
                ['exception' => $e->getMessage()],
                $correlationId,
            );
        }
    }

    private function recordUsage(?User $user, array $response): void
    {
        if (! $user) {
            return;
        }

        $usage = $response['usage'] ?? null;
        $total = 0;
        if (is_array($usage)) {
            $total = (int) ($usage['total_tokens'] ?? 0);
            if ($total <= 0) {
                $total = (int) ($usage['prompt_tokens'] ?? 0) + (int) ($usage['completion_tokens'] ?? 0);
            }
        }

        $this->subscriptions->recordTokenUsage($user, $total);
    }

    private function syncLocalChatHeader(Request $request, array $payload, array $response): void
    {
        $chatId = trim((string) ($response['chat_id'] ?? $payload['chat_id'] ?? ''));
        if ($chatId === '') {
            return;
        }

        $user = $request->user();
        $ownerEmail = strtolower(trim((string) ($user?->email ?? $payload['user_id'] ?? '')));
        if ($ownerEmail === '') {
            return;
        }

        $title = trim((string) ($response['chat_title'] ?? $payload['chat_title'] ?? ''));
        if ($title === '') {
            $query = trim((string) ($payload['query'] ?? $response['query'] ?? ''));
            $title = $query !== '' ? Str::limit($query, 80, '') : 'New chat';
        }

        ChatHeader::withTrashed()->updateOrCreate(
            [
                'owner_user_id' => $ownerEmail,
                'chat_id' => $chatId,
            ],
            [
                'owner_user_uuid' => $user?->id,
                'title' => Str::limit($title, 80, ''),
                'deleted_at' => null,
            ]
        );
    }

    private function upstreamError(FastApiException $e, string $correlationId): JsonResponse
    {
        return $this->error(
            $e->status(),
            $e->errorCode(),
            $this->sanitizeUserFacingMessage($e->getMessage(), $e->status()),
            $e->details(),
            $correlationId,
        );
    }

    private function sanitizeUserFacingMessage(string $message, int $status): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'Unable to process your request right now. Please try again.';
        }

        if ($status >= 500 || preg_match('/Chat failed|Voice chat failed|Errno|Invalid argument|Traceback|Exception|FastAPI|upstream|socket|connection refused/i', $message)) {
            return 'Unable to process your request right now. Please try again.';
        }

        if (strlen($message) > 160 || preg_match('/[{[\]\\\\<>]/', $message)) {
            return 'Unable to process your request right now. Please try again.';
        }

        return $message;
    }

    private function error(
        int $status,
        string $code,
        string $message,
        array|string|null $details,
        string $correlationId,
    ): JsonResponse {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'details' => $details ?? [],
            'correlation_id' => $correlationId,
        ], $status);
    }
}
