<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\SystemConfigService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveSubscription
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('admin');
        if (! $user instanceof User) {
            return $this->error($request, 401, 'unauthorized', 'Unauthorized request.');
        }

        if (! $this->systemConfig->isRequirePlanForChat() && $user->role === 'user') {
            return $next($request);
        }

        $check = $this->subscriptions->requireActiveWithQuota($user, 1);
        if (! ($check['ok'] ?? false)) {
            return $this->error(
                $request,
                $check['code'] === 'token_quota_exceeded' ? 402 : 402,
                $check['code'] ?? 'subscription_required',
                $check['message'] ?? 'Subscription required.',
                [
                    'subscription' => isset($check['subscription'])
                        ? $this->subscriptions->summary($check['subscription'])
                        : null,
                    'requires_reactivation' => (bool) ($check['requires_reactivation'] ?? false),
                    'auto_renew' => false,
                ]
            );
        }

        if (! empty($check['subscription'])) {
            $request->attributes->set('active_subscription', $check['subscription']);
        }

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
