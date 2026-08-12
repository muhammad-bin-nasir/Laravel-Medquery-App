<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateAdminToken
{
    public function __construct(private readonly JwtTokenService $jwtTokenService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            return $this->unauthorized('Authentication required.', 'token_missing');
        }

        try {
            $payload = $this->jwtTokenService->decodeAndValidate($token);
            $admin = User::query()->find($payload['sub'] ?? null);
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException && str_contains(strtolower($e->getMessage()), 'expired')) {
                return $this->unauthorized('Your session has expired. Please sign in again.', 'token_expired');
            }

            $admin = null;
        }

        if (! $admin) {
            return $this->unauthorized('Your session is invalid. Please sign in again.', 'token_invalid');
        }

        if (! $admin->isActiveAccount()) {
            return response()->json([
                'detail' => 'This account has been deactivated.',
                'code' => 'account_inactive',
            ], 403);
        }

        $request->attributes->set('admin', $admin);
        Auth::setUser($admin);

        return $next($request);
    }

    private function unauthorized(string $detail, string $code): JsonResponse
    {
        return response()->json([
            'detail' => $detail,
            'code' => $code,
        ], 401);
    }
}
