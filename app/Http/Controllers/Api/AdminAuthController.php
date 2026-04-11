<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Services\JwtTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
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

        $emailNormalized = $this->normalizeEmail($payload['email']);

        $admin = User::query()->where('email_normalized', $emailNormalized)->first();
        if (!$admin || !Hash::check($payload['password'], $admin->password_hash)) {
            return response()->json(['detail' => 'Invalid credentials'], 401);
        }

        if (!in_array($admin->role, ['admin', 'user', 'super_admin'], true)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $businessClientId = null;
        $workspaceId = null;

        if ($admin->business_id) {
            $business = Business::query()->find($admin->business_id);
            $businessClientId = $business?->business_client_id;
        }

        if ($admin->workspace_id) {
            $workspace = Workspace::query()->find($admin->workspace_id);
            $workspaceId = $workspace?->workspace_id;
        }

        $token = $this->jwtTokenService->createForUser($admin);

        return response()->json([
            'access_token' => $token['access_token'],
            'business_client_id' => $businessClientId,
            'workspace_id' => $workspaceId,
            'role' => $admin->role,
        ]);
    }

    public function createAdmin(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $emailNormalized = $this->normalizeEmail($payload['email']);

        $existing = User::query()->where('email_normalized', $emailNormalized)->first();
        if ($existing) {
            return response()->json(['detail' => 'User already exists'], 400);
        }

        User::query()->create([
            'email' => $emailNormalized,
            'email_normalized' => $emailNormalized,
            'password_hash' => Hash::make($payload['password']),
            'role' => 'admin',
            'business_id' => null,
            'workspace_id' => null,
        ]);

        return response()->json(['status' => 'created']);
    }

    public function createUser(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6'],
            'business_client_id' => ['required', 'string', 'max:100'],
            'workspace_id' => ['required', 'string', 'max:100'],
        ]);

        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!in_array($admin->role, ['admin', 'super_admin'], true)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $business = Business::query()->where('business_client_id', $payload['business_client_id'])->first();
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if ($admin->role === 'admin' && $business->admin_id && $business->admin_id !== $admin->id) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $workspace = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $payload['workspace_id'])
            ->first();

        if (!$workspace) {
            return response()->json(['detail' => 'Workspace not found'], 404);
        }

        $emailNormalized = $this->normalizeEmail($payload['email']);

        $existing = User::query()
            ->where('business_id', $business->id)
            ->where('email_normalized', $emailNormalized)
            ->first();

        if ($existing) {
            return response()->json(['detail' => 'User already exists'], 400);
        }

        User::query()->create([
            'email' => $emailNormalized,
            'email_normalized' => $emailNormalized,
            'password_hash' => Hash::make($payload['password']),
            'role' => 'user',
            'business_id' => $business->id,
            'workspace_id' => $workspace->id,
        ]);

        return response()->json(['status' => 'created']);
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
