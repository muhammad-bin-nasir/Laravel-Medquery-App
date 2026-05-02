<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Services\JwtTokenService;
use App\Services\ProjectApiException;
use App\Services\ProjectApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminBusinessController extends Controller
{
    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly ProjectApiService $projectApiService,
    ) {
    }

    public function create(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (!$this->canCreateBusiness($admin)) {
            return response()->json(['detail' => 'Admin or super admin required'], 403);
        }

        $payload = $request->validate([
            'business_client_id' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $existing = Business::query()
            ->where('business_client_id', $payload['business_client_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'detail' => 'Business already exists',
                'code' => 'business_already_exists',
            ], 409);
        }

        $existingName = Business::query()
            ->where('name', $payload['name'])
            ->first();

        if ($existingName) {
            return response()->json([
                'detail' => 'Business name already exists',
                'code' => 'business_name_already_exists',
            ], 409);
        }

        try {
            $jwtToken = $this->jwtTokenService->createForProjectUser($admin)['access_token'];
            app(\App\Services\ProjectApiService::class)->withToken($jwtToken)->createBusiness([
                'business_client_id' => $payload['business_client_id'],
                'name' => $payload['name'],
            ]);
        } catch (\App\Services\ProjectApiException $e) {
            if ($e->getStatus() !== 409) {
                return response()->json([
                    'detail' => 'Failed to sync business to Project backend',
                    'errors' => $e->getBody() ?? $e->getMessage(),
                ], 500);
            }
        }

        $business = Business::query()->create([
            'business_client_id' => $payload['business_client_id'],
            'name' => $payload['name'],
            'admin_id' => $admin->id,
        ]);

        return response()->json($this->toBusinessOut($business), 201);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        if ($admin->role === 'admin') {
            $businesses = Business::query()->where('admin_id', $admin->id)->get();
        } elseif ($admin->role === 'super_admin') {
            $businesses = Business::query()->get();
        } else {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        return response()->json(
            $businesses->map(fn (Business $business): array => $this->toBusinessOut($business))->values()
        );
    }

    public function show(Request $request, string $business_client_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        $business = Business::query()->where('business_client_id', $business_client_id)->first();
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (!$this->canAccessBusiness($admin, $business)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        return response()->json($this->toBusinessOut($business));
    }

    public function delete(Request $request, string $business_client_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        $business = Business::query()->where('business_client_id', $business_client_id)->first();
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (!$this->canAccessBusiness($admin, $business)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        try {
            $jwtToken = $this->jwtTokenService->createForProjectUser($admin)['access_token'];
            $this->projectApiService->withToken($jwtToken)->deleteBusiness($business->business_client_id);
        } catch (ProjectApiException $e) {
            return response()->json([
                'detail' => 'Failed to sync business deletion to Project backend',
                'code' => 'business_delete_sync_failed',
                'errors' => $e->getBody() ?? $e->getMessage(),
            ], $e->getStatus());
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'detail' => 'Failed to sync business deletion to Project backend',
                'code' => 'business_delete_sync_failed',
            ], 500);
        }

        DB::transaction(function () use ($business): void {
            User::query()->where('business_id', $business->id)->delete();
            Workspace::query()->where('business_client_id', $business->business_client_id)->delete();
            $business->delete();
        });

        return response()->json([
            'status' => 'deleted',
            'cascade' => 'workspaces_users_documents',
        ]);
    }

    private function canCreateBusiness(User $admin): bool
    {
        return in_array($admin->role, ['admin', 'super_admin'], true);
    }

    private function canAccessBusiness(User $admin, Business $business): bool
    {
        if ($admin->role === 'super_admin') {
            return true;
        }

        if ($admin->role === 'admin' && $business->admin_id === $admin->id) {
            return true;
        }

        return false;
    }

    private function toBusinessOut(Business $business): array
    {
        return [
            'business_client_id' => $business->business_client_id,
            'name' => $business->name,
            'admin_id' => $business->admin_id,
        ];
    }
}
