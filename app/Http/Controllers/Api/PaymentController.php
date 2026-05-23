<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    private const PLANS = [
        'basic' => ['amount' => 900,  'label' => 'Basic',  'currency' => 'usd'],
        'plus'  => ['amount' => 1900, 'label' => 'Plus',   'currency' => 'usd'],
        'pro'   => ['amount' => 4900, 'label' => 'Pro',    'currency' => 'usd'],
    ];

    /**
     * Create a Stripe PaymentIntent for the selected plan and return the client_secret.
     * The frontend uses the client_secret to confirm the payment using Stripe.js Elements.
     */
    public function createIntent(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'plan' => ['required', 'string', 'in:basic,plus,pro'],
        ]);

        $plan   = self::PLANS[$payload['plan']];
        $secret = config('services.stripe.secret');

        $response = Http::withBasicAuth($secret, '')
            ->asForm()
            ->post('https://api.stripe.com/v1/payment_intents', [
                'amount'                    => $plan['amount'],
                'currency'                  => $plan['currency'],
                'payment_method_types[]'    => 'card',
                'metadata[plan]'            => $payload['plan'],
            ]);

        if (!$response->successful()) {
            $body = $response->json();
            $message = $body['error']['message'] ?? 'Failed to create payment intent';
            return response()->json(['detail' => $message], $response->status());
        }

        $data = $response->json();

        return response()->json([
            'client_secret'    => $data['client_secret'],
            'payment_intent_id' => $data['id'],
            'amount'           => $plan['amount'],
            'currency'         => $plan['currency'],
            'plan'             => $payload['plan'],
        ]);
    }

    /**
     * Verify the PaymentIntent with Stripe and save the plan on the authenticated user.
     */
    public function confirmPlan(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'plan'               => ['required', 'string', 'in:basic,plus,pro'],
            'payment_intent_id'  => ['required', 'string'],
        ]);

        $secret   = config('services.stripe.secret');
        $intentId = $payload['payment_intent_id'];

        $response = Http::withBasicAuth($secret, '')
            ->get("https://api.stripe.com/v1/payment_intents/{$intentId}");

        if (!$response->successful()) {
            $body = $response->json();
            $message = $body['error']['message'] ?? 'Failed to verify payment';
            return response()->json(['detail' => $message], $response->status());
        }

        $intent = $response->json();

        if (($intent['status'] ?? '') !== 'succeeded') {
            return response()->json([
                'detail' => 'Payment has not succeeded. Status: ' . ($intent['status'] ?? 'unknown'),
            ], 402);
        }

        /** @var User $user */
        $user = $request->attributes->get('admin');
        $user->plan = $payload['plan'];
        $user->save();

        return response()->json([
            'status' => 'ok',
            'plan'   => $user->plan,
        ]);
    }
}
