<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectApiException extends RuntimeException
{
    private int $status;
    private array|string|null $body;

    public function __construct(string $message, int $status, array|string|null $body = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->body = $body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): array|string|null
    {
        return $this->body;
    }
}

class ProjectApiService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.project.base_url', 'http://127.0.0.1:8000/api'), '/');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout(120);
    }

    private function handleResponse(Response $response, string $endpoint): array
    {
        if (!$response->successful()) {
            $body = null;
            try {
                $body = $response->json();
            } catch (\Throwable) {
                $body = $response->body();
            }

            throw new ProjectApiException(
                sprintf('Project API request failed: %s returned %s', $endpoint, $response->status()),
                $response->status(),
                $body
            );
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new ProjectApiException('Project API returned invalid JSON for '.$endpoint, $response->status(), $response->body());
        }

        return $json;
    }

    public function chatGenerate(array $payload): array
    {
        $response = $this->client()->post('/chat/generate', $payload);
        return $this->handleResponse($response, '/chat/generate');
    }

    public function streamChat(array $payload): StreamedResponse
    {
        $response = $this->client()
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->withOptions(['stream' => true])
            ->post('/chat/stream', $payload);

        if (!$response->successful()) {
            $body = null;
            try {
                $body = $response->json();
            } catch (\Throwable) {
                $body = $response->body();
            }

            throw new ProjectApiException(
                sprintf('Project API request failed: %s returned %s', '/chat/stream', $response->status()),
                $response->status(),
                $body
            );
        }

        $stream = $response->toPsrResponse()->getBody();

        return response()->stream(function () use ($stream): void {
            while (!$stream->eof()) {
                echo $stream->read(4096);
                @ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
