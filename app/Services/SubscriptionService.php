<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SubscriptionService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REPLACED = 'replaced';

    public function activeSubscription(User $user): ?Subscription
    {
        $subscription = Subscription::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->where('status', self::STATUS_ACTIVE)
            ->orderByDesc('current_period_end')
            ->first();

        if (! $subscription) {
            return null;
        }

        return $this->syncExpiry($subscription) ? null : $subscription;
    }

    /**
     * Latest subscription for the user (any status), with expiry synced.
     * Used for UI when chat is blocked — shows expired/cancelled state and reactivation CTA.
     */
    public function latestSubscription(User $user): ?Subscription
    {
        $subscription = Subscription::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->orderByDesc('current_period_end')
            ->orderByDesc('created_at')
            ->first();

        if (! $subscription) {
            return null;
        }

        $this->syncExpiry($subscription);

        return $subscription->fresh(['plan']);
    }

    /**
     * Mark active subscriptions past period end as expired. Never auto-reactivates.
     *
     * @return bool true when the subscription is no longer usable as active
     */
    public function syncExpiry(Subscription $subscription): bool
    {
        if ($subscription->status !== self::STATUS_ACTIVE) {
            return $subscription->status !== self::STATUS_ACTIVE;
        }

        if ($subscription->current_period_end && $subscription->current_period_end->isPast()) {
            $subscription->status = self::STATUS_EXPIRED;
            $subscription->save();

            $user = $subscription->user;
            if ($user && $user->subscription_id === $subscription->id) {
                // Keep plan slug for reactivation UX; clear active pointer.
                $user->subscription_id = null;
                $user->save();
            }

            return true;
        }

        return false;
    }

    /**
     * Payload used by auth/me and my-subscription for chat gating.
     */
    public function statusForUser(User $user): array
    {
        $active = $this->activeSubscription($user);
        if ($active) {
            return [
                'subscription' => $this->summary($active),
                'requires_plan' => false,
                'requires_reactivation' => false,
                'can_chat' => true,
            ];
        }

        $latest = $this->latestSubscription($user);
        $needsReactivation = $latest && in_array($latest->status, [self::STATUS_EXPIRED, self::STATUS_CANCELLED], true);
        $isSiteUser = $user->role === 'user';
        $requirePlan = $isSiteUser && app(SystemConfigService::class)->isRequirePlanForChat();

        return [
            'subscription' => $this->summary($latest),
            'requires_plan' => $requirePlan,
            'requires_reactivation' => $requirePlan && $needsReactivation,
            'can_chat' => ! $requirePlan,
        ];
    }

    public function requireActiveWithQuota(User $user, int $estimateTokens = 1): array
    {
        if (in_array($user->role, ['admin', 'super_admin', 'sub_admin'], true)) {
            return ['ok' => true, 'subscription' => null, 'bypass' => true];
        }

        if (! app(SystemConfigService::class)->isRequirePlanForChat()) {
            return ['ok' => true, 'subscription' => null, 'bypass' => true];
        }

        $subscription = $this->activeSubscription($user);
        if (! $subscription) {
            $latest = $this->latestSubscription($user);
            $expired = $latest && in_array($latest->status, [self::STATUS_EXPIRED, self::STATUS_CANCELLED], true);

            return [
                'ok' => false,
                'code' => $expired ? 'subscription_expired' : 'subscription_required',
                'message' => $expired
                    ? 'Your subscription has expired. Reactivate it manually to continue chatting.'
                    : 'An active plan is required before you can chat. Please purchase a plan.',
                'subscription' => $latest,
                'requires_reactivation' => $expired,
            ];
        }

        if (! $subscription->hasTokenQuota($estimateTokens)) {
            return [
                'ok' => false,
                'code' => 'token_quota_exceeded',
                'message' => 'Your monthly token limit has been reached. Upgrade or renew your plan to continue.',
                'subscription' => $subscription,
            ];
        }

        return ['ok' => true, 'subscription' => $subscription, 'bypass' => false];
    }

    public function recordTokenUsage(User $user, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        $subscription = $this->activeSubscription($user);
        if (! $subscription) {
            return;
        }

        $subscription->tokens_used = (int) $subscription->tokens_used + $tokens;
        $subscription->save();
    }

    public function activateFromPayment(User $user, $plan, string $paymentIntentId): Subscription
    {
        return $this->startMonthlyPeriod($user, $plan, [
            'stripe_payment_intent_id' => $paymentIntentId,
            'replace_active' => true,
        ]);
    }

    /**
     * Admin assigns / activates a monthly subscription for a user (no Stripe charge).
     */
    public function activateForUser(User $user, Plan $plan, array $options = []): Subscription
    {
        return $this->startMonthlyPeriod($user, $plan, [
            'stripe_payment_intent_id' => $options['stripe_payment_intent_id'] ?? null,
            'replace_active' => true,
            'tokens_used' => (int) ($options['tokens_used'] ?? 0),
            'period_start' => $options['period_start'] ?? null,
            'period_end' => $options['period_end'] ?? null,
        ]);
    }

    /**
     * Manual reactivation: starts a fresh monthly period from the plan quotas.
     * Does not charge Stripe — user reactivation via payment uses activateFromPayment.
     */
    public function reactivate(Subscription $subscription, ?Plan $plan = null): Subscription
    {
        $plan = $plan ?: $subscription->plan;
        if (! $plan) {
            throw new InvalidArgumentException('A plan is required to reactivate this subscription.');
        }

        $user = $subscription->user;
        if (! $user) {
            throw new InvalidArgumentException('Subscription has no user.');
        }

        return $this->startMonthlyPeriod($user, $plan, [
            'stripe_payment_intent_id' => $subscription->stripe_payment_intent_id,
            'replace_active' => true,
            'source_subscription_id' => $subscription->id,
        ]);
    }

    public function cancel(Subscription $subscription): Subscription
    {
        $this->syncExpiry($subscription);

        if ($subscription->status === self::STATUS_ACTIVE) {
            $subscription->status = self::STATUS_CANCELLED;
            $subscription->save();
        } elseif ($subscription->status !== self::STATUS_CANCELLED) {
            $subscription->status = self::STATUS_CANCELLED;
            $subscription->save();
        }

        $user = $subscription->user;
        if ($user && $user->subscription_id === $subscription->id) {
            $user->subscription_id = null;
            $user->save();
        }

        return $subscription->fresh(['plan', 'user']);
    }

    public function updateSubscription(Subscription $subscription, array $payload): Subscription
    {
        $this->syncExpiry($subscription);

        if (isset($payload['plan_id'])) {
            $plan = Plan::query()->findOrFail($payload['plan_id']);
            $subscription->plan_id = $plan->id;
            if (! array_key_exists('tokens_included', $payload)) {
                $subscription->tokens_included = (int) $plan->monthly_token_limit;
            }
            if (! array_key_exists('amount_cents', $payload)) {
                $subscription->amount_cents = (int) $plan->price_cents;
            }
            if (! array_key_exists('currency', $payload)) {
                $subscription->currency = $plan->currency ?: 'usd';
            }

            $user = $subscription->user;
            if ($user && $subscription->status === self::STATUS_ACTIVE) {
                $user->plan = $plan->slug;
                $user->save();
            }
        }

        foreach (['status', 'tokens_included', 'tokens_used', 'amount_cents', 'currency'] as $field) {
            if (array_key_exists($field, $payload)) {
                $subscription->{$field} = $payload[$field];
            }
        }

        if (array_key_exists('current_period_start', $payload)) {
            $subscription->current_period_start = $payload['current_period_start']
                ? Carbon::parse($payload['current_period_start'])
                : null;
        }

        if (array_key_exists('current_period_end', $payload)) {
            $subscription->current_period_end = $payload['current_period_end']
                ? Carbon::parse($payload['current_period_end'])
                : null;
        }

        // Activating via update must clear other actives and wire the user.
        if (($payload['status'] ?? null) === self::STATUS_ACTIVE) {
            Subscription::query()
                ->where('user_id', $subscription->user_id)
                ->where('id', '!=', $subscription->id)
                ->where('status', self::STATUS_ACTIVE)
                ->update(['status' => self::STATUS_REPLACED]);

            if ($subscription->current_period_end && $subscription->current_period_end->isPast()) {
                $now = Carbon::now();
                $subscription->current_period_start = $now;
                $subscription->current_period_end = $now->copy()->addMonth();
            }

            $user = $subscription->user;
            if ($user) {
                $plan = $subscription->plan;
                $user->subscription_id = $subscription->id;
                if ($plan) {
                    $user->plan = $plan->slug;
                }
                $user->save();
            }
        }

        if (in_array($subscription->status, [self::STATUS_EXPIRED, self::STATUS_CANCELLED, self::STATUS_REPLACED], true)) {
            $user = $subscription->user;
            if ($user && $user->subscription_id === $subscription->id) {
                $user->subscription_id = null;
                $user->save();
            }
        }

        $subscription->save();

        return $subscription->fresh(['plan', 'user']);
    }

    public function deleteSubscription(Subscription $subscription): void
    {
        $user = $subscription->user;
        if ($user && $user->subscription_id === $subscription->id) {
            $user->subscription_id = null;
            $user->save();
        }

        $subscription->delete();
    }

    /**
     * Starts a fresh 1-month period tied to the plan's token quota and price.
     * Prior active rows are marked replaced — never auto-extended.
     */
    public function startMonthlyPeriod(User $user, Plan $plan, array $options = []): Subscription
    {
        return DB::transaction(function () use ($user, $plan, $options) {
            if (! empty($options['replace_active'])) {
                Subscription::query()
                    ->where('user_id', $user->id)
                    ->where('status', self::STATUS_ACTIVE)
                    ->update(['status' => self::STATUS_REPLACED]);
            }

            $now = Carbon::now();
            $periodStart = ! empty($options['period_start'])
                ? Carbon::parse($options['period_start'])
                : $now;
            $periodEnd = ! empty($options['period_end'])
                ? Carbon::parse($options['period_end'])
                : $periodStart->copy()->addMonth();

            $subscription = Subscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => self::STATUS_ACTIVE,
                'stripe_payment_intent_id' => $options['stripe_payment_intent_id'] ?? null,
                'tokens_included' => (int) $plan->monthly_token_limit,
                'tokens_used' => (int) ($options['tokens_used'] ?? 0),
                'amount_cents' => (int) $plan->price_cents,
                'currency' => $plan->currency ?: 'usd',
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ]);

            $user->plan = $plan->slug;
            $user->subscription_id = $subscription->id;
            $user->save();

            return $subscription->fresh(['plan', 'user']);
        });
    }

    public function summary(?Subscription $subscription): ?array
    {
        if (! $subscription) {
            return null;
        }

        $this->syncExpiry($subscription);
        $subscription->loadMissing('plan');

        $included = (int) $subscription->tokens_included;
        $used = (int) $subscription->tokens_used;
        $remaining = $subscription->tokensRemaining();
        $usagePercent = $included > 0
            ? round(min(100, ($used / $included) * 100), 2)
            : 0.0;

        $plan = $subscription->plan;
        $isActive = $subscription->isActive();

        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'is_active' => $isActive,
            'billing_interval' => 'month',
            'auto_renew' => false,
            'plan' => $plan ? [
                'id' => $plan->id,
                'slug' => $plan->slug,
                'name' => $plan->name,
                'description' => $plan->description,
                'price_cents' => (int) $plan->price_cents,
                'price_display' => $plan->formattedPrice(),
                'currency' => $plan->currency ?: 'usd',
                'monthly_token_limit' => (int) $plan->monthly_token_limit,
                'features' => is_array($plan->features) ? $plan->features : [],
            ] : null,
            'tokens_included' => $included,
            'tokens_used' => $used,
            'tokens_remaining' => $remaining,
            'usage_percent' => $usagePercent,
            'amount_cents' => (int) $subscription->amount_cents,
            'currency' => $subscription->currency ?: 'usd',
            'current_period_start' => optional($subscription->current_period_start)?->toISOString(),
            'current_period_end' => optional($subscription->current_period_end)?->toISOString(),
            'can_reactivate' => in_array($subscription->status, [self::STATUS_EXPIRED, self::STATUS_CANCELLED], true) && (bool) $plan,
        ];
    }

    public function adminSerialize(Subscription $subscription): array
    {
        $base = $this->summary($subscription) ?? [];
        $subscription->loadMissing(['user', 'plan']);
        $user = $subscription->user;

        return [
            ...$base,
            'user_id' => $subscription->user_id,
            'plan_id' => $subscription->plan_id,
            'stripe_payment_intent_id' => $subscription->stripe_payment_intent_id,
            'created_at' => optional($subscription->created_at)?->toISOString(),
            'updated_at' => optional($subscription->updated_at)?->toISOString(),
            'user' => $user ? [
                'id' => $user->id,
                'email' => $user->email,
                'display_name' => $user->display_name,
                'role' => $user->role,
                'plan' => $user->plan,
                'business_client_id' => $user->business_client_id,
                'workspace_id' => $user->workspace_id,
            ] : null,
        ];
    }
}
