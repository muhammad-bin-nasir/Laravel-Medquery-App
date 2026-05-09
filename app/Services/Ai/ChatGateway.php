<?php

namespace App\Services\Ai;

use App\Exceptions\FastApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatGateway
{
    public function __construct(private readonly FastApiClient $fastApiClient)
    {
    }

    public function chat(array $payload, string $correlationId): array
    {
        $mapped = $this->mapChatPayload($payload);

        Log::info('ai.chat.request.start', [
            'correlation_id' => $correlationId,
            'business_client_id' => $mapped['business_client_id'] ?? null,
            'workspace_id' => $mapped['workspace_id'] ?? null,
            'user_id' => $mapped['user_id'] ?? null,
            'has_image' => isset($mapped['image_data_url']),
        ]);

        try {
            $response = $this->fastApiClient->chatGenerate($mapped, $correlationId);

            Log::info('ai.chat.request.success', [
                'correlation_id' => $correlationId,
                'chat_id' => $response['chat_id'] ?? null,
            ]);

            return $response;
        } catch (FastApiException $e) {
            Log::warning('ai.chat.request.failed', [
                'correlation_id' => $correlationId,
                'status' => $e->status(),
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } catch (Throwable $e) {
            Log::error('ai.chat.request.crashed', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);

            throw new FastApiException(
                502,
                'upstream_unavailable',
                'Unable to process chat request with AI service.',
                ['exception' => $e->getMessage()]
            );
        }
    }

    public function stream(array $payload, string $correlationId): StreamedResponse
    {
        $mapped = $this->mapChatPayload($payload);

        Log::info('ai.chat.stream.start', [
            'correlation_id' => $correlationId,
            'business_client_id' => $mapped['business_client_id'] ?? null,
            'workspace_id' => $mapped['workspace_id'] ?? null,
            'user_id' => $mapped['user_id'] ?? null,
            'has_image' => isset($mapped['image_data_url']),
        ]);

        try {
            return $this->fastApiClient->streamChat($mapped, $correlationId);
        } catch (FastApiException $e) {
            Log::warning('ai.chat.stream.failed', [
                'correlation_id' => $correlationId,
                'status' => $e->status(),
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } catch (Throwable $e) {
            Log::error('ai.chat.stream.crashed', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);

            throw new FastApiException(
                502,
                'upstream_unavailable',
                'Unable to start chat stream with AI service.',
                ['exception' => $e->getMessage()]
            );
        }
    }

    public function voice(array $payload, UploadedFile $audioFile, string $correlationId): array
    {
        $mapped = $this->mapVoicePayload($payload);

        Log::info('ai.chat.voice.request.start', [
            'correlation_id' => $correlationId,
            'business_client_id' => $mapped['business_client_id'] ?? null,
            'workspace_id' => $mapped['workspace_id'] ?? null,
            'user_id' => $mapped['user_id'] ?? null,
            'audio_mime' => $audioFile->getMimeType(),
            'audio_size' => $audioFile->getSize(),
        ]);

        try {
            $response = $this->fastApiClient->chatVoice($mapped, $audioFile, $correlationId);

            Log::info('ai.chat.voice.request.success', [
                'correlation_id' => $correlationId,
                'chat_id' => $response['chat_id'] ?? null,
            ]);

            return $response;
        } catch (FastApiException $e) {
            Log::warning('ai.chat.voice.request.failed', [
                'correlation_id' => $correlationId,
                'status' => $e->status(),
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } catch (Throwable $e) {
            Log::error('ai.chat.voice.request.crashed', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);

            throw new FastApiException(
                502,
                'upstream_unavailable',
                'Unable to process voice chat request with AI service.',
                ['exception' => $e->getMessage()]
            );
        }
    }

    public function retrieve(array $payload, string $correlationId): array
    {
        $mapped = $this->mapRetrievePayload($payload);

        Log::info('ai.retrieve.request.start', [
            'correlation_id' => $correlationId,
            'business_client_id' => $mapped['business_client_id'] ?? null,
            'workspace_id' => $mapped['workspace_id'] ?? null,
            'user_id' => $mapped['user_id'] ?? null,
            'top_k' => $mapped['top_k'] ?? null,
        ]);

        try {
            $response = $this->fastApiClient->retrieve($mapped, $correlationId);

            Log::info('ai.retrieve.request.success', [
                'correlation_id' => $correlationId,
                'retrieved_count' => is_array($response['retrieved_chunks'] ?? null)
                    ? count($response['retrieved_chunks'])
                    : null,
            ]);

            return $response;
        } catch (FastApiException $e) {
            Log::warning('ai.retrieve.request.failed', [
                'correlation_id' => $correlationId,
                'status' => $e->status(),
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } catch (Throwable $e) {
            Log::error('ai.retrieve.request.crashed', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);

            throw new FastApiException(
                502,
                'upstream_unavailable',
                'Unable to process retrieval request with AI service.',
                ['exception' => $e->getMessage()]
            );
        }
    }

    private function mapChatPayload(array $payload): array
    {
        $mapped = [
            'business_client_id' => $payload['business_client_id'] ?? null,
            'workspace_id' => $payload['workspace_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'query' => $payload['query'] ?? null,
            'chat_id' => $payload['chat_id'] ?? null,
            'chat_title' => $payload['chat_title'] ?? null,
            'prompt_engineering' => $payload['prompt_engineering'] ?? null,
            'image_data_url' => $payload['image_data_url'] ?? null,
            'chat_config_override' => $payload['chat_config_override'] ?? null,
        ];

        return array_filter($mapped, static fn (mixed $value): bool => $value !== null);
    }

    private function mapRetrievePayload(array $payload): array
    {
        $mapped = [
            'business_client_id' => $payload['business_client_id'] ?? null,
            'workspace_id' => $payload['workspace_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'query' => $payload['query'] ?? null,
            'top_k' => $payload['top_k'] ?? null,
        ];

        return array_filter($mapped, static fn (mixed $value): bool => $value !== null);
    }

    private function mapVoicePayload(array $payload): array
    {
        $mapped = [
            'business_client_id' => $payload['business_client_id'] ?? null,
            'workspace_id' => $payload['workspace_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'chat_id' => $payload['chat_id'] ?? null,
            'chat_title' => $payload['chat_title'] ?? null,
            'prompt_engineering' => $payload['prompt_engineering'] ?? null,
        ];

        return array_filter($mapped, static fn (mixed $value): bool => $value !== null);
    }
}
