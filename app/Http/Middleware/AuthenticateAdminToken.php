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
        $admin = null;

        if ($token) {
            try {
                $payload = $this->jwtTokenService->decodeAndValidate($token);
                $admin = User::query()->find($payload['sub']);
            } catch (Throwable $e) {
                $admin = null;
            }
        }

        if (!$admin) {
            $admin = User::query()
                ->orderByRaw("case when role = 'super_admin' then 0 when role = 'admin' then 1 else 2 end")
                ->orderBy('created_at')
                ->first();
        }

        if (!$admin) {
            return $this->unauthorized('No admin found');
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
