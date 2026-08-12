<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class StripeBillingService
{
    public function secretKey(): string
    {
        $secret = trim((string) app(SystemConfigService::class)->stripeSecretKey());
        if ($secret === '' || str_contains($secret, 'placeholder')) {
            throw new RuntimeException('stripe_not_configured');
        }

        return $secret;
    }

    public function isConfigured(): bool
    {
        return app(SystemConfigService::class)->isStripeConfigured();
    }

    public function ensureCustomer(User $user): string
    {
        if (! empty($user->stripe_customer_id)) {
            return (string) $user->stripe_customer_id;
        }

        $response = $this->request('POST', 'https://api.stripe.com/v1/customers', [
            'email' => $user->email,
            'name' => $user->display_name ?: $user->email,
            'metadata[user_id]' => $user->id,
        ]);

        if (! $response->successful()) {
            Log::error('stripe.create_customer_failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'stripe_error' => $response->json('error.message'),
            ]);
            throw new RuntimeException('stripe_customer_failed');
        }

        $customerId = (string) ($response->json('id') ?? '');
        if ($customerId === '') {
            throw new RuntimeException('stripe_customer_failed');
        }

        $user->stripe_customer_id = $customerId;
        $user->save();

        return $customerId;
    }

    public function createPaymentIntent(User $user, array $params): array
    {
        $customerId = $this->ensureCustomer($user);

        $payload = array_merge($params, [
            'customer' => $customerId,
            'setup_future_usage' => 'off_session',
            'payment_method_types[]' => 'card',
        ]);

        $response = $this->request('POST', 'https://api.stripe.com/v1/payment_intents', $payload);
        if (! $response->successful()) {
            Log::error('stripe.create_payment_intent_failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'stripe_error' => $response->json('error.message'),
            ]);
            throw new RuntimeException('stripe_payment_intent_failed');
        }

        return $response->json();
    }

    public function retrievePaymentIntent(string $intentId): array
    {
        $response = $this->request('GET', "https://api.stripe.com/v1/payment_intents/{$intentId}");
        if (! $response->successful()) {
            Log::error('stripe.retrieve_payment_intent_failed', [
                'payment_intent_id' => $intentId,
                'status' => $response->status(),
                'stripe_error' => $response->json('error.message'),
            ]);
            throw new RuntimeException('stripe_retrieve_intent_failed');
        }

        return $response->json();
    }

    public function createSetupIntent(User $user): array
    {
        $customerId = $this->ensureCustomer($user);

        $response = $this->request('POST', 'https://api.stripe.com/v1/setup_intents', [
            'customer' => $customerId,
            'payment_method_types[]' => 'card',
            'usage' => 'off_session',
            'metadata[user_id]' => $user->id,
        ]);

        if (! $response->successful()) {
            Log::error('stripe.create_setup_intent_failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'stripe_error' => $response->json('error.message'),
            ]);
            throw new RuntimeException('stripe_setup_intent_failed');
        }

        return $response->json();
    }

    public function retrieveSetupIntent(string $setupIntentId): array
    {
        $response = $this->request('GET', "https://api.stripe.com/v1/setup_intents/{$setupIntentId}");
        if (! $response->successful()) {
            Log::error('stripe.retrieve_setup_intent_failed', [
                'setup_intent_id' => $setupIntentId,
                'status' => $response->status(),
                'stripe_error' => $response->json('error.message'),
            ]);
            throw new RuntimeException('stripe_retrieve_setup_failed');
        }

        return $response->json();
    }

    public function attachPaymentMethodFromIntent(User $user, array $intent): ?array
    {
        $paymentMethodId = is_string($intent['payment_method'] ?? null)
            ? $intent['payment_method']
            : (string) ($intent['payment_method']['id'] ?? '');

        if ($paymentMethodId === '') {
            return null;
        }

        return $this->setDefaultPaymentMethod($user, $paymentMethodId);
    }

    public function setDefaultPaymentMethod(User $user, string $paymentMethodId): array
    {
        $customerId = $this->ensureCustomer($user);

        // Attach PM to customer (idempotent if already attached).
        $attach = $this->request('POST', "https://api.stripe.com/v1/payment_methods/{$paymentMethodId}/attach", [
            'customer' => $customerId,
        ]);
        if (! $attach->successful()) {
            $code = (string) ($attach->json('error.code') ?? '');
            // Already attached to this customer is fine.
            if ($code !== 'resource_already_exists' && $attach->status() !== 400) {
                Log::warning('stripe.attach_payment_method_failed', [
                    'user_id' => $user->id,
                    'payment_method_id' => $paymentMethodId,
                    'status' => $attach->status(),
                    'stripe_error' => $attach->json('error.message'),
                ]);
            }
        }

        $this->request('POST', "https://api.stripe.com/v1/customers/{$customerId}", [
            'invoice_settings[default_payment_method]' => $paymentMethodId,
        ]);

        if (! empty($user->stripe_payment_method_id) && $user->stripe_payment_method_id !== $paymentMethodId) {
            $this->request('POST', "https://api.stripe.com/v1/payment_methods/{$user->stripe_payment_method_id}/detach");
        }

        $user->stripe_payment_method_id = $paymentMethodId;
        $user->save();

        return $this->paymentMethodSummary($paymentMethodId) ?? [
            'id' => $paymentMethodId,
            'brand' => null,
            'last4' => null,
            'exp_month' => null,
            'exp_year' => null,
        ];
    }

    public function paymentMethodForUser(User $user): ?array
    {
        if (empty($user->stripe_payment_method_id)) {
            return null;
        }

        return $this->paymentMethodSummary((string) $user->stripe_payment_method_id);
    }

    public function paymentMethodSummary(string $paymentMethodId): ?array
    {
        $response = $this->request('GET', "https://api.stripe.com/v1/payment_methods/{$paymentMethodId}");
        if (! $response->successful()) {
            Log::warning('stripe.retrieve_payment_method_failed', [
                'payment_method_id' => $paymentMethodId,
                'status' => $response->status(),
                'stripe_error' => $response->json('error.message'),
            ]);

            return null;
        }

        $card = $response->json('card') ?? [];

        return [
            'id' => $paymentMethodId,
            'brand' => $card['brand'] ?? null,
            'last4' => $card['last4'] ?? null,
            'exp_month' => isset($card['exp_month']) ? (int) $card['exp_month'] : null,
            'exp_year' => isset($card['exp_year']) ? (int) $card['exp_year'] : null,
        ];
    }

    private function request(string $method, string $url, array $form = []): Response
    {
        $pending = Http::withBasicAuth($this->secretKey(), '')->asForm();

        return match (strtoupper($method)) {
            'GET' => $pending->get($url),
            'POST' => $pending->post($url, $form),
            default => throw new RuntimeException('unsupported_http_method'),
        };
    }
}
