<?php

namespace App\Services;

use App\Models\SiteLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SiteLogService
{
    private static bool $recording = false;

    public const SEVERITIES = ['debug', 'info', 'warning', 'error', 'critical'];

    public const SOURCES = ['laravel', 'python', 'frontend', 'chat', 'admin', 'user', 'system'];

    /**
     * Persist a site log row. Never throws — logging must not break the app.
     */
    public function record(array $payload, ?Request $request = null): ?SiteLog
    {
        if (self::$recording) {
            return null;
        }

        self::$recording = true;

        try {
            $request = $request ?: request();
            $user = $this->resolveUser($payload, $request);

            $severity = $this->normalizeEnum(
                (string) ($payload['severity'] ?? 'error'),
                self::SEVERITIES,
                'error'
            );
            $source = $this->normalizeEnum(
                (string) ($payload['source'] ?? 'laravel'),
                self::SOURCES,
                'laravel'
            );

            $message = trim((string) ($payload['message'] ?? ''));
            if ($message === '') {
                $message = 'Unknown error';
            }
            $message = Str::limit($message, 5000, '…');

            $stack = isset($payload['stack_trace'])
                ? Str::limit((string) $payload['stack_trace'], 50000, "\n…[truncated]")
                : null;

            $context = $payload['context'] ?? $payload['context_json'] ?? null;
            if (is_string($context)) {
                $decoded = json_decode($context, true);
                $context = is_array($decoded) ? $decoded : ['raw' => Str::limit($context, 2000)];
            }
            if (! is_array($context)) {
                $context = null;
            }

            $correlationId = trim((string) (
                $payload['correlation_id']
                ?? $request?->attributes?->get('correlation_id')
                ?? $request?->header('X-Correlation-Id')
                ?? ''
            ));

            return SiteLog::query()->create([
                'severity' => $severity,
                'source' => $source,
                'category' => $this->nullableString($payload['category'] ?? null, 64),
                'message' => $message,
                'exception_class' => $this->nullableString($payload['exception_class'] ?? null, 255),
                'stack_trace' => $stack,
                'context_json' => $context,
                'correlation_id' => $correlationId !== '' ? Str::limit($correlationId, 64, '') : null,
                'user_id' => $user?->id,
                'user_email' => $user?->email
                    ?: $this->nullableString($payload['user_email'] ?? null, 255),
                'user_role' => $user?->role
                    ?: $this->nullableString($payload['user_role'] ?? null, 32),
                'request_method' => $this->nullableString(
                    $payload['request_method'] ?? $request?->method(),
                    16
                ),
                'request_path' => $this->nullableString(
                    $payload['request_path'] ?? $request?->path(),
                    512
                ),
                'request_url' => $this->nullableString(
                    $payload['request_url'] ?? ($request ? $request->fullUrl() : null),
                    2000
                ),
                'ip_address' => $this->nullableString(
                    $payload['ip_address'] ?? $request?->ip(),
                    64
                ),
                'user_agent' => $this->nullableString(
                    $payload['user_agent'] ?? $request?->userAgent(),
                    1000
                ),
                'status_code' => isset($payload['status_code'])
                    ? (int) $payload['status_code']
                    : null,
            ]);
        } catch (Throwable) {
            return null;
        } finally {
            self::$recording = false;
        }
    }

    public function recordException(Throwable $e, ?Request $request = null, array $extra = []): ?SiteLog
    {
        if ($this->shouldSkipException($e)) {
            return null;
        }

        $request = $request ?: request();
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
        $severity = $status >= 500 || ! ($e instanceof HttpExceptionInterface)
            ? ($status >= 500 ? 'error' : 'warning')
            : 'warning';

        if ($status >= 500 && ($e instanceof \Error || $e instanceof \ErrorException)) {
            $severity = 'critical';
        }

        $source = (string) ($extra['source'] ?? 'laravel');
        $path = (string) ($request?->path() ?? '');
        if ($source === 'laravel') {
            if (str_starts_with($path, 'api/ai') || str_starts_with($path, 'api/chat') || str_starts_with($path, 'api/rag')) {
                $source = 'chat';
            } elseif (str_starts_with($path, 'api/admin')) {
                $source = 'admin';
            } elseif (str_starts_with($path, 'api/auth') || str_starts_with($path, 'api/payments') || str_starts_with($path, 'api/help')) {
                $source = 'user';
            }
        }

        return $this->record(array_merge([
            'severity' => $severity,
            'source' => $source,
            'category' => $extra['category'] ?? class_basename($e),
            'message' => $e->getMessage() !== '' ? $e->getMessage() : class_basename($e),
            'exception_class' => $e::class,
            'stack_trace' => $this->formatTrace($e),
            'status_code' => $status,
            'context' => array_filter([
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'code' => $e->getCode(),
                'previous' => $e->getPrevious() ? [
                    'class' => $e->getPrevious()::class,
                    'message' => $e->getPrevious()->getMessage(),
                ] : null,
                'extra' => $extra['context'] ?? null,
            ]),
        ], $extra), $request);
    }

    private function shouldSkipException(Throwable $e): bool
    {
        if ($e instanceof ValidationException) {
            return true;
        }

        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return true;
        }

        // Avoid noise from missing routes / auth challenges.
        $class = $e::class;
        foreach ([
            'Illuminate\\Auth\\AuthenticationException',
            'Illuminate\\Auth\\Access\\AuthorizationException',
            'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException',
            'Symfony\\Component\\HttpKernel\\Exception\\MethodNotAllowedHttpException',
            'Illuminate\\Session\\TokenMismatchException',
        ] as $skip) {
            if ($e instanceof $skip || $class === $skip) {
                return true;
            }
        }

        return false;
    }

    private function formatTrace(Throwable $e): string
    {
        return Str::limit($e->getMessage()."\n".$e->getTraceAsString(), 50000, "\n…[truncated]");
    }

    private function resolveUser(array $payload, ?Request $request): ?User
    {
        $fromRequest = $request?->attributes?->get('admin');
        if ($fromRequest instanceof User) {
            return $fromRequest;
        }

        $authUser = Auth::user();
        if ($authUser instanceof User) {
            return $authUser;
        }

        $userId = trim((string) ($payload['user_id'] ?? ''));
        if ($userId !== '') {
            return User::query()->find($userId);
        }

        return null;
    }

    private function normalizeEnum(string $value, array $allowed, string $default): string
    {
        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : Str::limit($text, $max, '');
    }
}
