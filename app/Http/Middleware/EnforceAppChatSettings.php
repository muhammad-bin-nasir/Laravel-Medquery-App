<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SystemConfigService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceAppChatSettings
{
    public function __construct(private readonly SystemConfigService $config)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('admin');
        $role = $user instanceof User ? (string) $user->role : '';

        if ($this->config->isMaintenanceMode()) {
            return $this->blocked(
                $request,
                503,
                'maintenance_mode',
                $this->config->maintenanceMessage()
            );
        }

        if (! $this->config->isChatEnabledForRole($role)) {
            $isStaff = in_array(strtolower($role), ['admin', 'super_admin', 'sub_admin'], true);

            return $this->blocked(
                $request,
                403,
                $isStaff ? 'admin_chat_disabled' : 'user_chat_disabled',
                $isStaff
                    ? 'Admin chat is currently disabled by application settings.'
                    : 'Chat is currently disabled by application settings.'
            );
        }

        if ($request->is('api/ai/chat/voice') && ! $this->config->isVoiceChatEnabled()) {
            return $this->blocked(
                $request,
                403,
                'voice_chat_disabled',
                'Voice chat is currently disabled by application settings.'
            );
        }

        return $next($request);
    }

    private function blocked(Request $request, int $status, string $code, string $message): JsonResponse
    {
        $correlationId = (string) $request->attributes->get('correlation_id', '');

        return response()->json([
            'detail' => $message,
            'code' => $code,
            'message' => $message,
            'correlation_id' => $correlationId !== '' ? $correlationId : null,
        ], $status);
    }
}
