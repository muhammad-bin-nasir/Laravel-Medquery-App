<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private readonly JwtTokenService $jwtTokenService)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $emailNormalized = strtolower(trim($payload['email']));

        $user = User::query()->where('email_normalized', $emailNormalized)->first();
        if (!$user || !Hash::check($payload['password'], $user->password_hash)) {
            return response()->json(['detail' => 'Invalid credentials'], 401);
        }

        $token = $this->jwtTokenService->createForUser($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'business_id' => $user->business_id,
                'workspace_id' => $user->workspace_id,
            ],
            'session' => $token,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'business_id' => $user->business_id,
            'workspace_id' => $user->workspace_id,
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');

        return response()->json([
            'session' => $this->jwtTokenService->createForUser($user),
        ]);
    }
}
