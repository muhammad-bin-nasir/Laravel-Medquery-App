<?php

namespace App\Services\Ai;

use App\Exceptions\FastApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FastApiClient
{
    private string $baseUrl;
    private string $token;
    private int $timeout;
    private int $connectTimeout;
    private int $streamTimeout;
    private int $retryTimes;
    private int $retrySleepMs;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.fastapi.base_url', 'http://127.0.0.1:8000'), '/');
        $this->token = trim((string) config('services.fastapi.token', ''));
        $this->timeout = max((int) config('services.fastapi.timeout', 120), 1);
        $this->connectTimeout = max((int) config('services.fastapi.connect_timeout', 10), 1);
        $this->streamTimeout = max((int) config('services.fastapi.stream_timeout', 300), 1);
        $this->retryTimes = max((int) config('services.fastapi.retry_times', 2), 0);
        $this->retrySleepMs = max((int) config('services.fastapi.retry_sleep_ms', 200), 0);
    }

    public function chatGenerate(array $payload, string $correlationId): array
    {
        try {
            $response = $this->request($correlationId)->post($this->endpoint('/api/chat/generate'), $payload);
        } catch (RequestException $e) {
            if ($e->response instanceof Response) {
                throw $this->toException($e->response, '/api/chat/generate');
            }

            throw new FastApiException(
                502,
                'upstream_unavailable',
                'Unable to connect to AI service chat endpoint.',
                ['exception' => $e->getMessage()]
            );
        } catch (ConnectionException $e) {
            throw new FastApiException(
                504,
                'upstream_timeout',
                'AI service timed out while processing chat request.',
                ['exception' => $e->getMessage()]
            );
        } catch (Throwable $e) {
            throw new FastApiException(
                502,
                'upstream_unavailable',
                'Unable to connect to AI service chat endpoint.',
                ['exception' => $e->getMessage()]
            );
        }

        return $this->decodeJsonResponse($response, '/api/chat/generate');
    }

    public function retrieve(array $payload, string $correlationId): array
    {
        try {
            $response = $this->request($correlationId)->post($this->endpoint('/api/rag/retrieve'), $payload);
        } catch (RequestException $e) {
            if ($e->response instanceof Response) {
                throw $this->toException($e->response, '/api/rag/retrieve');
            }

            throw new FastApiException(
                502,
                'upstream_unavailable',
                'Unable to connect to AI service retrieval endpoint.',
                ['exception' => $e->getMessage()]
            );
        } catch (ConnectionException $e) {
            throw new FastApiException(
                504,
                'upstream_timeout',
                'AI service timed out while processing retrieval request.',
                ['exception' => $e->getMessage()]
            );
        } catch (Throwable $e) {
            throw new FastApiException(
                502,
                'upstream_unavailable',
                'Unable to connect to AI service retrieval endpoint.',
                ['exception' => $e->getMessage()]
            );
        }

        return $this->decodeJsonResponse($response, '/api/rag/retrieve');
    }

    public function streamChat(array $payload, string $correlationId): StreamedResponse
    {
        try {
            $response = $this->request($correlationId, true)
                ->withOptions(['stream' => true])
                ->post($this->endpoint('/api/chat/stream'), $payload);
        } catch (RequestException $e) {
            if ($e->response instanceof Response) {
                throw $this->toException($e->response, '/api/chat/stream');
            }

            throw new FastApiException(
                502,
                'upstream_unavailable',
                'Unable to connect to AI service stream endpoint.',
                ['exception' => $e->getMessage()]
            );
        } catch (ConnectionException $e) {
            throw new FastApiException(
                504,
                'upstream_timeout',
                'AI service timed out while starting the stream.',
                ['exception' => $e->getMessage()]
            );
        } catch (Throwable $e) {
            throw new FastApiException(
                502,
                'upstream_unavailable',
                'Unable to connect to AI service stream endpoint.',
                ['exception' => $e->getMessage()]
            );
        }

        if (!$response->successful()) {
            throw $this->toException($response, '/api/chat/stream');
        }

        $stream = $response->toPsrResponse()->getBody();

        return response()->stream(function () use ($stream, $correlationId): void {
            try {
                while (!$stream->eof()) {
                    if (connection_aborted()) {
                        Log::info('ai.stream.client_disconnected', [
                            'correlation_id' => $correlationId,
                        ]);
                        break;
                    }

                    $chunk = $stream->read(8192);
                    if ($chunk === '') {
                        continue;
                    }

                    echo $chunk;
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
            } catch (Throwable $e) {
                Log::warning('ai.stream.forward_failed', [
                    'correlation_id' => $correlationId,
                    'error' => $e->getMessage(),
                ]);

                $payload = [
                    'code' => 'stream_proxy_failed',
                    'message' => 'AI stream proxy failed.',
                    'details' => ['exception' => $e->getMessage()],
                    'correlation_id' => $correlationId,
                ];

                echo 'event: error' . "\n";
                echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            } finally {
                if (method_exists($stream, 'close')) {
                    $stream->close();
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function request(string $correlationId, bool $streaming = false): PendingRequest
    {
        $request = Http::acceptJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($streaming ? $this->streamTimeout : $this->timeout)
            ->retry(
                $this->retryTimes,
                $this->retrySleepMs,
                static fn (Throwable $e): bool => $e instanceof ConnectionException,
                throw: false,
            )
            ->withHeaders([
                'X-Correlation-Id' => $correlationId,
            ]);

        if ($streaming) {
            $request = $request->withHeaders([
                'Accept' => 'text/event-stream',
            ]);
        }

        if ($this->token !== '') {
            $request = $request->withToken($this->token);
        }

        return $request;
    }

    private function endpoint(string $path): string
    {
        $normalizedPath = '/'.ltrim($path, '/');

        if (str_ends_with($this->baseUrl, '/api') && str_starts_with($normalizedPath, '/api/')) {
            $normalizedPath = substr($normalizedPath, 4);
        }

        return $this->baseUrl.$normalizedPath;
    }

    private function decodeJsonResponse(Response $response, string $endpoint): array
    {
        if (!$response->successful()) {
            throw $this->toException($response, $endpoint);
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new FastApiException(
                502,
                'invalid_upstream_response',
                'AI service returned an invalid JSON response.',
                ['endpoint' => $endpoint, 'body' => $response->body()]
            );
        }

        return $json;
    }

    private function toException(Response $response, string $endpoint): FastApiException
    {
        $status = $response->status();
        $body = null;

        try {
            $body = $response->json();
        } catch (Throwable) {
            $body = $response->body();
        }

        $message = is_array($body)
            ? (string) ($body['detail'] ?? $body['message'] ?? 'Upstream AI request failed.')
            : (string) ($body ?: 'Upstream AI request failed.');

        $code = is_array($body) && isset($body['code'])
            ? (string) $body['code']
            : $this->defaultCode($status);

        return new FastApiException(
            $status,
            $code,
            $message,
            [
                'endpoint' => $endpoint,
                'upstream_status' => $status,
                'upstream_body' => $body,
            ]
        );
    }

    private function defaultCode(int $status): string
    {
        return match ($status) {
            400 => 'upstream_bad_request',
            401 => 'upstream_unauthorized',
            403 => 'upstream_forbidden',
            404 => 'upstream_not_found',
            422 => 'upstream_validation_error',
            429 => 'upstream_rate_limited',
            default => $status >= 500 ? 'upstream_server_error' : 'upstream_error',
        };
    }
}
