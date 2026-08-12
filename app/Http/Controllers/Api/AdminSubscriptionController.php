<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class AdminSubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $status = strtolower(trim((string) $request->query('status', '')));
        $search = trim((string) $request->query('search', ''));
        $userId = trim((string) $request->query('user_id', ''));

        $query = Subscription::query()
            ->with(['user', 'plan'])
            ->orderByDesc('current_period_end')
            ->orderByDesc('created_at');

        if ($userId !== '') {
            $query->where('user_id', $userId);
        }

        if (in_array($status, ['active', 'expired', 'cancelled', 'replaced'], true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->whereHas('user', function ($builder) use ($search) {
                $builder->where('email', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%")
                    ->orWhere('email_normalized', 'like', '%'.strtolower($search).'%');
            });
        }

        $items = $query->limit(500)->get();

        // Sync expired rows so list status stays accurate without auto-renew.
        $items->each(fn (Subscription $subscription) => $this->subscriptions->syncExpiry($subscription));
        $items = $items->map(fn (Subscription $subscription) => $subscription->fresh(['user', 'plan']));

        if (in_array($status, ['active', 'expired', 'cancelled', 'replaced'], true)) {
            $items = $items->filter(fn (Subscription $subscription) => $subscription->status === $status)->values();
        }

        return response()->json([
            'subscriptions' => $items->map(fn (Subscription $subscription) => $this->subscriptions->adminSerialize($subscription))->values(),
            'meta' => [
                'billing_interval' => 'month',
                'auto_renew' => false,
                'note' => 'Subscriptions expire at period end and must be reactivated manually. Chat stays blocked until reactivation.',
            ],
        ]);
    }

    public function show(Request $request, string $subscriptionId): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $subscription = Subscription::query()->with(['user', 'plan'])->find($subscriptionId);
        if (! $subscription) {
            return response()->json(['detail' => 'Subscription not found.'], 404);
        }

        $this->subscriptions->syncExpiry($subscription);

        return response()->json([
            'subscription' => $this->subscriptions->adminSerialize($subscription->fresh(['user', 'plan'])),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $payload = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'plan_id' => ['required', 'uuid', 'exists:plans,id'],
            'tokens_used' => ['nullable', 'integer', 'min:0'],
            'current_period_start' => ['nullable', 'date'],
            'current_period_end' => ['nullable', 'date', 'after:current_period_start'],
        ]);

        $user = User::query()->findOrFail($payload['user_id']);
        if ($user->role !== 'user') {
            return response()->json(['detail' => 'Subscriptions apply to site users only.'], 422);
        }

        $plan = Plan::query()->findOrFail($payload['plan_id']);
        if (! $plan->is_active) {
            return response()->json(['detail' => 'This plan is inactive.'], 422);
        }

        $subscription = $this->subscriptions->activateForUser($user, $plan, [
            'tokens_used' => $payload['tokens_used'] ?? 0,
            'period_start' => $payload['current_period_start'] ?? null,
            'period_end' => $payload['current_period_end'] ?? null,
        ]);

        return response()->json([
            'status' => 'ok',
            'subscription' => $this->subscriptions->adminSerialize($subscription),
        ], 201);
    }

    public function update(Request $request, string $subscriptionId): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $subscription = Subscription::query()->with(['user', 'plan'])->find($subscriptionId);
        if (! $subscription) {
            return response()->json(['detail' => 'Subscription not found.'], 404);
        }

        $payload = $request->validate([
            'plan_id' => ['nullable', 'uuid', 'exists:plans,id'],
            'status' => ['nullable', Rule::in(['active', 'expired', 'cancelled', 'replaced'])],
            'tokens_included' => ['nullable', 'integer', 'min:0'],
            'tokens_used' => ['nullable', 'integer', 'min:0'],
            'amount_cents' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'current_period_start' => ['nullable', 'date'],
            'current_period_end' => ['nullable', 'date'],
        ]);

        try {
            $updated = $this->subscriptions->updateSubscription($subscription, $payload);
        } catch (Throwable $e) {
            return response()->json(['detail' => 'Could not update subscription.'], 422);
        }

        return response()->json([
            'status' => 'ok',
            'subscription' => $this->subscriptions->adminSerialize($updated),
        ]);
    }

    public function destroy(Request $request, string $subscriptionId): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $subscription = Subscription::query()->find($subscriptionId);
        if (! $subscription) {
            return response()->json(['detail' => 'Subscription not found.'], 404);
        }

        $this->subscriptions->deleteSubscription($subscription);

        return response()->json(['status' => 'ok']);
    }

    public function activate(Request $request, string $subscriptionId): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $subscription = Subscription::query()->with(['user', 'plan'])->find($subscriptionId);
        if (! $subscription) {
            return response()->json(['detail' => 'Subscription not found.'], 404);
        }

        $updated = $this->subscriptions->updateSubscription($subscription, ['status' => 'active']);

        return response()->json([
            'status' => 'ok',
            'subscription' => $this->subscriptions->adminSerialize($updated),
        ]);
    }

    public function reactivate(Request $request, string $subscriptionId): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $subscription = Subscription::query()->with(['user', 'plan'])->find($subscriptionId);
        if (! $subscription) {
            return response()->json(['detail' => 'Subscription not found.'], 404);
        }

        $payload = $request->validate([
            'plan_id' => ['nullable', 'uuid', 'exists:plans,id'],
        ]);

        $plan = ! empty($payload['plan_id'])
            ? Plan::query()->findOrFail($payload['plan_id'])
            : $subscription->plan;

        if (! $plan) {
            return response()->json(['detail' => 'A plan is required to reactivate.'], 422);
        }

        try {
            $renewed = $this->subscriptions->reactivate($subscription, $plan);
        } catch (Throwable $e) {
            return response()->json(['detail' => $e->getMessage() ?: 'Could not reactivate subscription.'], 422);
        }

        return response()->json([
            'status' => 'ok',
            'subscription' => $this->subscriptions->adminSerialize($renewed),
            'message' => 'Subscription reactivated for a new monthly period. Chat is unlocked for this user.',
        ]);
    }

    public function cancel(Request $request, string $subscriptionId): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $subscription = Subscription::query()->with(['user', 'plan'])->find($subscriptionId);
        if (! $subscription) {
            return response()->json(['detail' => 'Subscription not found.'], 404);
        }

        $cancelled = $this->subscriptions->cancel($subscription);

        return response()->json([
            'status' => 'ok',
            'subscription' => $this->subscriptions->adminSerialize($cancelled),
            'message' => 'Subscription cancelled. Chat is blocked until the user reactivates.',
        ]);
    }

    private function isAdminLike(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin'], true);
    }
}
