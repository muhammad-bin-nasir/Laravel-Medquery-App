<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        if (!$token) {
            return $this->unauthorized('Missing bearer token');
        }

        try {
            $payload = $this->jwtTokenService->decodeAndValidate($token);
        } catch (Throwable $e) {
            return $this->unauthorized('Invalid token');
        }

        $admin = User::query()->find($payload['sub']);
        if (!$admin) {
            return $this->unauthorized('Admin not found');
        }

        $request->attributes->set('admin', $admin);
        Auth::setUser($admin);

        return $next($request);
    }

    private function unauthorized(string $detail): JsonResponse
    {
        return response()->json(['detail' => $detail], 401);
    }
}
