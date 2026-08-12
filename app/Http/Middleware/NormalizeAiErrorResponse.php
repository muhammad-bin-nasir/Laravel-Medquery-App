<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeAiErrorResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$request->is('api/ai/*') || $response->getStatusCode() < 400) {
            return $response;
        }

        $correlationId = (string) $request->attributes->get('correlation_id', '');
        $status = $response->getStatusCode();

        $payload = [];
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data)) {
                $payload = $data;
            }
        }

        if (
            isset($payload['code'])
            && isset($payload['message'])
            && array_key_exists('details', $payload)
            && isset($payload['correlation_id'])
        ) {
            return $response;
        }

        $message = (string) (
            $payload['message']
            ?? $payload['detail']
            ?? Response::$statusTexts[$status]
            ?? 'Request failed'
        );

        $details = $payload['details']
            ?? $payload['context']
            ?? $payload['errors']
            ?? $this->fallbackDetails($payload);

        $normalized = [
            'code' => (string) ($payload['code'] ?? $this->defaultCode($status)),
            'message' => $message,
            'details' => $details,
            'correlation_id' => $correlationId,
        ];

        if ($response instanceof JsonResponse) {
            $response->setData($normalized);
            return $response;
        }

        return response()->json($normalized, $status);
    }

    private function fallbackDetails(array $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        return ['upstream' => $payload];
    }

    private function defaultCode(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            409 => 'conflict',
            422 => 'validation_error',
            429 => 'rate_limited',
            502 => 'upstream_unavailable',
            504 => 'upstream_timeout',
            default => 'ai_gateway_error',
        };
    }
}
