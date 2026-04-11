<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBusinessController extends Controller
{
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
            return response()->json(['detail' => 'Business already exists'], 400);
        }

        $existingName = Business::query()
            ->where('name', $payload['name'])
            ->first();

        if ($existingName) {
            return response()->json(['detail' => 'Business name already exists'], 400);
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
