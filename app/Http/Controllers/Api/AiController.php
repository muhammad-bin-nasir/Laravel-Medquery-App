<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FastApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatRequest;
use App\Http\Requests\AiRetrieveRequest;
use App\Services\Ai\ChatGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AiController extends Controller
{
    public function __construct(private readonly ChatGateway $chatGateway)
    {
    }

    public function chat(AiChatRequest $request): JsonResponse
    {
        $correlationId = (string) $request->attributes->get('correlation_id', '');

        try {
            return response()->json($this->chatGateway->chat($request->validated(), $correlationId));
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
            $audioFile = $request->file('audio_file');

            return response()->json($this->chatGateway->voice($validated, $audioFile, $correlationId));
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

    private function upstreamError(FastApiException $e, string $correlationId): JsonResponse
    {
        return $this->error(
            $e->status(),
            $e->errorCode(),
            $e->getMessage(),
            $e->details(),
            $correlationId,
        );
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
