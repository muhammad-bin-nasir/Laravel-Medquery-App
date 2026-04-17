<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeApiErrorResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$request->is('api/*')) {
            return $response;
        }

        if ($response->getStatusCode() < 400) {
            return $response;
        }

        if (!$response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $detail = $payload['detail'] ?? $payload['message'] ?? Response::$statusTexts[$response->getStatusCode()] ?? 'Request failed';
        $code = $payload['code'] ?? $this->defaultCode($response->getStatusCode());

        $context = [];
        if (isset($payload['context']) && is_array($payload['context'])) {
            $context = $payload['context'];
        }

        $correlationId = (string) $request->attributes->get('correlation_id', '');
        if ($correlationId !== '') {
            $context['correlation_id'] = $correlationId;
        }

        if ($response->getStatusCode() === 422 && isset($payload['errors']) && is_array($payload['errors'])) {
            $context['errors'] = $payload['errors'];
        }

        $response->setData([
            'detail' => (string) $detail,
            'code' => (string) $code,
            'context' => $context,
        ]);

        return $response;
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
            default => 'api_error',
        };
    }
}
