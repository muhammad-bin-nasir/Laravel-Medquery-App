<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthAbuseProtectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthChallengeController extends Controller
{
    public function __construct(private readonly AuthAbuseProtectionService $abuse)
    {
    }

    public function show(Request $request): JsonResponse
    {
        if ($this->abuse->isBlocked($request)) {
            return response()->json([
                'detail' => 'Too many failed attempts from this IP. Try again later.',
                'code' => 'ip_blocked',
                'retry_after' => $this->abuse->blockRemainingSeconds($request),
            ], 429);
        }

        return response()->json($this->abuse->issueChallenge());
    }
}
