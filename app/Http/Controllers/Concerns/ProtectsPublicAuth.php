<?php

namespace App\Http\Controllers\Concerns;

use App\Services\AuthAbuseProtectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait ProtectsPublicAuth
{
    protected function enforceAuthProtection(Request $request, string $action = 'auth'): ?JsonResponse
    {
        /** @var AuthAbuseProtectionService $abuse */
        $abuse = app(AuthAbuseProtectionService::class);
        $result = $abuse->guard($request, $action);

        if ($result['ok'] ?? false) {
            return null;
        }

        return response()->json([
            'detail' => $result['detail'] ?? 'Request blocked.',
            'code' => $result['code'] ?? 'auth_blocked',
        ], (int) ($result['status'] ?? 429));
    }

    protected function recordAuthFailure(Request $request, string $action = 'auth', ?string $reason = null): void
    {
        app(AuthAbuseProtectionService::class)->registerFailure($request, $action, $reason);
    }

    protected function clearAuthFailures(Request $request): void
    {
        app(AuthAbuseProtectionService::class)->clearFailures($request);
    }
}
