<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InjectTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('admin');
        if (!$user instanceof User) {
            return $this->error($request, 401, 'unauthorized', 'Unauthorized request.', [
                'reason' => 'auth_user_missing',
            ]);
        }

        $resolvedUserId = strtolower(trim((string) ($user->email_normalized ?: $user->email ?: $user->id)));

        if ($user->role === 'user') {
            $businessClientId = trim((string) $user->business_client_id);
            $workspaceInternalId = trim((string) $user->workspace_id);

            if ($businessClientId === '' || $workspaceInternalId === '') {
                return $this->error($request, 403, 'tenant_context_missing', 'Tenant context is not configured for this user.', [
                    'required_fields' => ['business_client_id', 'workspace_id'],
                ]);
            }

            $workspace = Workspace::query()->find($workspaceInternalId);
            if (!$workspace) {
                return $this->error($request, 403, 'workspace_not_found', 'Tenant workspace was not found for this user.', [
                    'workspace_id' => $workspaceInternalId,
                ]);
            }

            $request->merge([
                'business_client_id' => $businessClientId,
                'workspace_id' => (string) $workspace->workspace_id,
                'user_id' => $resolvedUserId,
            ]);
        } else {
            $defaults = [];
            if (!$request->filled('user_id')) {
                $defaults['user_id'] = $resolvedUserId;
            }

            if (!$request->filled('business_client_id') && !empty($user->business_client_id)) {
                $defaults['business_client_id'] = (string) $user->business_client_id;
            }

            if (!$request->filled('workspace_id') && !empty($user->workspace_id)) {
                $workspace = Workspace::query()->find((string) $user->workspace_id);
                if ($workspace) {
                    $defaults['workspace_id'] = (string) $workspace->workspace_id;
                }
            }

            if (!empty($defaults)) {
                $request->merge($defaults);
            }
        }

        $request->attributes->set('tenant_context', [
            'business_client_id' => $request->input('business_client_id'),
            'workspace_id' => $request->input('workspace_id'),
            'user_id' => $request->input('user_id'),
            'role' => $user->role,
        ]);

        return $next($request);
    }

    private function error(Request $request, int $status, string $code, string $message, array $details = []): JsonResponse
    {
        $correlationId = (string) $request->attributes->get('correlation_id', '');

        return response()->json([
            'code' => $code,
            'message' => $message,
            'details' => $details,
            'correlation_id' => $correlationId,
        ], $status);
    }
}
