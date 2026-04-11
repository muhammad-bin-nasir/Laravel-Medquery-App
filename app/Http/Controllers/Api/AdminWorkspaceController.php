<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminWorkspaceController extends Controller
{
    public function create(Request $request, string $business_client_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        $business = $this->resolveBusiness($business_client_id);
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (!$this->canAccessBusiness($admin, $business)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $payload = $request->validate([
            'workspace_id' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $existing = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $payload['workspace_id'])
            ->first();

        if ($existing) {
            return response()->json(['detail' => 'Workspace already exists'], 400);
        }

        $workspace = DB::transaction(function () use ($business, $payload): Workspace {
            $workspace = Workspace::query()->create([
                'business_client_id' => $business->business_client_id,
                'workspace_id' => $payload['workspace_id'],
                'name' => $payload['name'],
            ]);

            WorkspaceConfig::query()->create([
                'business_id' => $business->id,
                'workspace_id' => $workspace->id,
            ]);

            return $workspace;
        });

        return response()->json($this->toWorkspaceOut($workspace), 201);
    }

    public function index(Request $request, string $business_client_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        $business = $this->resolveBusiness($business_client_id);
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (!$this->canAccessBusiness($admin, $business)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $workspaces = Workspace::query()->where('business_client_id', $business->business_client_id)->get();

        return response()->json(
            $workspaces->map(fn (Workspace $workspace): array => $this->toWorkspaceOut($workspace))->values()
        );
    }

    public function show(Request $request, string $business_client_id, string $workspace_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        $business = $this->resolveBusiness($business_client_id);
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (!$this->canAccessBusiness($admin, $business)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $workspace = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $workspace_id)
            ->first();

        if (!$workspace) {
            return response()->json(['detail' => 'Workspace not found'], 404);
        }

        return response()->json($this->toWorkspaceOut($workspace));
    }

    public function delete(Request $request, string $business_client_id, string $workspace_id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        $business = $this->resolveBusiness($business_client_id);
        if (!$business) {
            return response()->json(['detail' => 'Business not found'], 404);
        }

        if (!$this->canAccessBusiness($admin, $business)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $workspace = Workspace::query()
            ->where('business_client_id', $business->business_client_id)
            ->where('workspace_id', $workspace_id)
            ->first();

        if (!$workspace) {
            return response()->json(['detail' => 'Workspace not found'], 404);
        }

        $workspace->delete();

        return response()->json([
            'status' => 'deleted',
            'cascade' => 'documents_and_chunks',
        ]);
    }

    private function resolveBusiness(string $business_client_id): ?Business
    {
        return Business::query()->where('business_client_id', $business_client_id)->first();
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

    private function toWorkspaceOut(Workspace $workspace): array
    {
        return [
            'workspace_id' => $workspace->workspace_id,
            'name' => $workspace->name,
        ];
    }
}
