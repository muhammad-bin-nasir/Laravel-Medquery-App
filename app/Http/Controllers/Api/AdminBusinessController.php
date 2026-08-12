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
            if ($e->getStatus() !== 409 && $e->getStatus() !== 400) {
                $body = $e->getBody();
                $upstream = is_array($body)
                    ? (string) ($body['detail'] ?? $body['message'] ?? json_encode($body))
                    : (is_string($body) && $body !== '' ? $body : $e->getMessage());

                $safeDetail = $this->userFacingUpstreamDetail($upstream, 'Unable to create business. Please try again.');

                return response()->json([
                    'detail' => $safeDetail,
                    'code' => 'business_sync_failed',
                ], $e->getStatus() >= 400 && $e->getStatus() < 600 ? $e->getStatus() : 500);
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
        } elseif ($admin->role === 'sub_admin' && $admin->created_by) {
            $businesses = Business::query()->where('admin_id', $admin->created_by)->get();
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

        return response()->json($this->toBusinessOut($business, true));
    }

    public function update(Request $request, string $business_client_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        if (! $this->canCreateBusiness($admin)) {
            return response()->json(['detail' => 'Admin or super admin required'], 403);
        }

        $business = Business::query()->where('business_client_id', $business_client_id)->first();
        if (! $business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (! $this->canAccessBusiness($admin, $business)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $nameConflict = Business::query()
            ->where('name', $payload['name'])
            ->where('business_client_id', '!=', $business->business_client_id)
            ->exists();

        if ($nameConflict) {
            return response()->json([
                'detail' => 'Business name already exists',
                'code' => 'business_name_already_exists',
            ], 409);
        }

        try {
            $jwtToken = $this->jwtTokenService->createForProjectUser($admin)['access_token'];
            $this->projectApiService->withToken($jwtToken)->updateBusiness($business->business_client_id, [
                'name' => $payload['name'],
            ]);
        } catch (ProjectApiException $e) {
            if (! in_array($e->getStatus(), [404, 400, 403], true)) {
                return response()->json([
                    'detail' => 'Unable to update business. Please try again.',
                    'code' => 'business_sync_failed',
                ], $e->getStatus() >= 400 && $e->getStatus() < 600 ? $e->getStatus() : 500);
            }
        }

        $business->name = $payload['name'];
        $business->save();

        return response()->json($this->toBusinessOut($business));
    }

    public function delete(Request $request, string $business_client_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        if (! $this->canCreateBusiness($admin)) {
            return response()->json(['detail' => 'Admin or super admin required'], 403);
        }

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
            if ($e->getStatus() !== 404) {
                return response()->json([
                    'detail' => 'Unable to delete business. Please try again.',
                    'code' => 'business_delete_sync_failed',
                ], $e->getStatus());
            }
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'detail' => 'Unable to delete business. Please try again.',
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
        ]);
    }

    private function canCreateBusiness(User $admin): bool
    {
        return in_array($admin->role, ['admin', 'super_admin'], true);
    }

    private function canAccessBusiness(User $admin, Business $business): bool
    {
        return \App\Services\StaffAccess::canAccessBusiness($admin, $business);
    }

    private function toBusinessOut(Business $business, bool $detailed = false): array
    {
        $workspaceCount = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->count();

        $payload = [
            'business_client_id' => $business->business_client_id,
            'name' => $business->name,
            'admin_id' => $business->admin_id,
            'workspace_count' => $workspaceCount,
            'created_at' => optional($business->created_at)?->toIso8601String(),
            'updated_at' => optional($business->updated_at)?->toIso8601String(),
        ];

        if ($detailed) {
            $payload['workspaces'] = Workspace::query()
                ->where('business_client_id', $business->business_client_id)
                ->orderBy('name')
                ->get()
                ->map(fn (Workspace $workspace): array => [
                    'workspace_id' => $workspace->workspace_id,
                    'name' => $workspace->name,
                ])
                ->values()
                ->all();
        }

        return $payload;
    }

    private function userFacingUpstreamDetail(string $upstream, string $fallback): string
    {
        $detail = trim($upstream);
        if ($detail === '') {
            return $fallback;
        }

        if (preg_match('/Project API|Project backend|FastAPI|Laravel|synced to/i', $detail)) {
            return $fallback;
        }

        return $detail;
    }
}
