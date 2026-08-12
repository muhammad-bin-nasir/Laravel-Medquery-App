<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Services\StripeBillingService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly StripeBillingService $stripe,
    ) {
    }

    /**
     * Create a Stripe PaymentIntent for the selected plan and return the client_secret.
     * Frontend confirms with Stripe.js Elements (payment stays on our site).
     */
    public function createIntent(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'plan' => ['required', 'string', 'max:50'],
            'plan_id' => ['nullable', 'uuid'],
        ]);

        $plan = $this->resolvePlan($payload['plan_id'] ?? null, $payload['plan']);
        if (! $plan || ! $plan->is_active) {
            return response()->json(['detail' => 'This plan is no longer available.'], 404);
        }

        if (! $this->stripe->isConfigured()) {
            Log::error('payments.stripe_not_configured', [
                'reason' => 'Stripe is disabled or keys are missing in Admin Settings / .env',
                'environment' => app(\App\Services\SystemConfigService::class)->stripeEnvironment(),
                'enabled' => app(\App\Services\SystemConfigService::class)->isStripeEnabled(),
            ]);

            return response()->json([
                'detail' => 'Card payments are temporarily unavailable. Please try again later.',
                'code' => 'payment_unavailable',
            ], 503);
        }

        /** @var User $user */
        $user = $request->attributes->get('admin');

        try {
            $data = $this->stripe->createPaymentIntent($user, [
                'amount' => $plan->price_cents,
                'currency' => $plan->currency ?: 'usd',
                'metadata[plan]' => $plan->slug,
                'metadata[plan_id]' => $plan->id,
                'metadata[user_id]' => $user->id,
                'receipt_email' => $user->email,
                'description' => "NursingAI {$plan->name} monthly plan ({$plan->monthly_token_limit} tokens)",
            ]);
        } catch (Throwable $e) {
            Log::error('payments.create_intent_unreachable', [
                'plan' => $plan->slug,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'detail' => 'We could not start the payment. Please try again.',
                'code' => 'payment_init_failed',
            ], 502);
        }

        return response()->json([
            'client_secret' => $data['client_secret'] ?? null,
            'payment_intent_id' => $data['id'] ?? null,
            'amount' => $plan->price_cents,
            'currency' => $plan->currency,
            'plan' => $plan->slug,
            'plan_id' => $plan->id,
            'price_display' => $plan->formattedPrice(),
            'monthly_token_limit' => (int) $plan->monthly_token_limit,
        ]);
    }

    /**
     * Verify the PaymentIntent with Stripe and activate a monthly subscription.
     */
    public function confirmPlan(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'plan' => ['required', 'string', 'max:50'],
            'plan_id' => ['nullable', 'uuid'],
            'payment_intent_id' => ['required', 'string'],
        ]);

        $intentId = $payload['payment_intent_id'];

        try {
            $intent = $this->stripe->retrievePaymentIntent($intentId);
        } catch (Throwable $e) {
            Log::error('payments.verify_intent_unreachable', [
                'payment_intent_id' => $intentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'detail' => 'We could not verify your payment. Please contact support if you were charged.',
                'code' => 'payment_verification_failed',
            ], 502);
        }

        if (($intent['status'] ?? '') !== 'succeeded') {
            return response()->json([
                'detail' => 'Payment has not succeeded. Please try again.',
                'code' => 'payment_incomplete',
            ], 402);
        }

        $plan = $this->resolvePlan($payload['plan_id'] ?? ($intent['metadata']['plan_id'] ?? null), $payload['plan']);
        if (! $plan) {
            return response()->json(['detail' => 'This plan is no longer available.'], 404);
        }

        $paidAmount = (int) ($intent['amount'] ?? 0);
        if ($paidAmount !== (int) $plan->price_cents) {
            Log::error('payments.amount_mismatch', [
                'paid_amount' => $paidAmount,
                'expected_amount' => (int) $plan->price_cents,
                'plan' => $plan->slug,
                'payment_intent_id' => $intentId,
            ]);

            return response()->json([
                'detail' => 'We could not confirm your payment. Please contact support.',
                'code' => 'payment_verification_failed',
            ], 422);
        }

        /** @var User $user */
        $user = $request->attributes->get('admin');
        $subscription = $this->subscriptions->activateFromPayment($user, $plan, $intentId);

        $paymentMethod = null;
        try {
            $paymentMethod = $this->stripe->attachPaymentMethodFromIntent($user, $intent);
        } catch (Throwable $e) {
            Log::warning('payments.attach_payment_method_failed', [
                'user_id' => $user->id,
                'payment_intent_id' => $intentId,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'plan' => $user->plan,
            'subscription' => $this->subscriptions->summary($subscription),
            'payment_method' => $paymentMethod,
        ]);
    }

    public function mySubscription(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');
        $status = $this->subscriptions->statusForUser($user);

        return response()->json([
            'plan' => $user->plan,
            'subscription' => $status['subscription'],
            'payment_method' => $this->safePaymentMethod($user),
            'requires_plan' => $status['requires_plan'],
            'requires_reactivation' => $status['requires_reactivation'],
            'can_chat' => $status['can_chat'],
            'auto_renew' => false,
            'billing_interval' => 'month',
        ]);
    }

    /**
     * User cancels their active subscription. Chat stops immediately; no auto-reactivation.
     */
    public function cancelSubscription(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');
        $subscription = $this->subscriptions->activeSubscription($user);

        if (! $subscription) {
            return response()->json([
                'detail' => 'No active subscription to cancel.',
                'code' => 'no_active_subscription',
            ], 404);
        }

        $cancelled = $this->subscriptions->cancel($subscription);
        $status = $this->subscriptions->statusForUser($user->fresh());

        return response()->json([
            'status' => 'ok',
            'message' => 'Subscription cancelled. Chat is paused until you reactivate manually.',
            'subscription' => $this->subscriptions->summary($cancelled),
            'requires_plan' => $status['requires_plan'],
            'requires_reactivation' => $status['requires_reactivation'],
            'can_chat' => $status['can_chat'],
        ]);
    }

    public function paymentMethod(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');

        return response()->json([
            'payment_method' => $this->safePaymentMethod($user),
        ]);
    }

    public function createSetupIntent(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');

        if (! $this->stripe->isConfigured()) {
            return response()->json([
                'detail' => 'Card payments are temporarily unavailable. Please try again later.',
                'code' => 'payment_unavailable',
            ], 503);
        }

        try {
            $intent = $this->stripe->createSetupIntent($user);
        } catch (Throwable $e) {
            Log::error('payments.setup_intent_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'detail' => 'We could not start card setup. Please try again.',
                'code' => 'setup_intent_failed',
            ], 502);
        }

        return response()->json([
            'client_secret' => $intent['client_secret'] ?? null,
            'setup_intent_id' => $intent['id'] ?? null,
        ]);
    }

    public function confirmPaymentMethod(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'setup_intent_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->attributes->get('admin');

        try {
            $intent = $this->stripe->retrieveSetupIntent($payload['setup_intent_id']);
        } catch (Throwable $e) {
            Log::error('payments.confirm_payment_method_unreachable', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'detail' => 'We could not save your card. Please try again.',
                'code' => 'payment_method_save_failed',
            ], 502);
        }

        if (($intent['status'] ?? '') !== 'succeeded') {
            return response()->json([
                'detail' => 'Card setup was not completed. Please try again.',
                'code' => 'setup_incomplete',
            ], 422);
        }

        $intentCustomer = is_string($intent['customer'] ?? null)
            ? $intent['customer']
            : (string) ($intent['customer']['id'] ?? '');

        if ($intentCustomer !== '' && ! empty($user->stripe_customer_id) && $intentCustomer !== $user->stripe_customer_id) {
            return response()->json([
                'detail' => 'We could not save your card. Please try again.',
                'code' => 'payment_method_save_failed',
            ], 403);
        }

        try {
            $paymentMethod = $this->stripe->attachPaymentMethodFromIntent($user, $intent);
        } catch (Throwable $e) {
            Log::error('payments.confirm_payment_method_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'detail' => 'We could not save your card. Please try again.',
                'code' => 'payment_method_save_failed',
            ], 502);
        }

        if (! $paymentMethod) {
            return response()->json([
                'detail' => 'We could not save your card. Please try again.',
                'code' => 'payment_method_save_failed',
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'payment_method' => $paymentMethod,
        ]);
    }

    public function publishableKey(): JsonResponse
    {
        $config = app(\App\Services\SystemConfigService::class);
        $key = $config->stripePublishableKey();
        $configured = $config->isStripeConfigured();

        return response()->json([
            'publishable_key' => $configured ? $key : null,
            'configured' => $configured,
            'enabled' => $config->isStripeEnabled(),
            'environment' => $config->stripeEnvironment(),
        ]);
    }

    private function resolvePlan(?string $planId, string $slug): ?Plan
    {
        if ($planId) {
            $byId = Plan::query()->find($planId);
            if ($byId) {
                return $byId;
            }
        }

        return Plan::query()->where('slug', strtolower(trim($slug)))->first();
    }

    private function safePaymentMethod(User $user): ?array
    {
        if (empty($user->stripe_payment_method_id) || ! $this->stripe->isConfigured()) {
            return null;
        }

        try {
            return $this->stripe->paymentMethodForUser($user);
        } catch (Throwable $e) {
            Log::warning('payments.payment_method_lookup_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
