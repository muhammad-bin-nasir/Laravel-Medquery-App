<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminPlanController extends Controller
{
    public function __construct(private readonly PlanPricingService $pricing)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $plans = Plan::query()->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['plans' => $plans->map(fn (Plan $plan) => $this->serialize($plan))->values()]);
    }

    public function publicIndex(): JsonResponse
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price_cents')
            ->get();

        return response()->json(['plans' => $plans->map(fn (Plan $plan) => $this->serialize($plan))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $payload = $request->validate([
            'slug' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:plans,slug'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'monthly_token_limit' => ['required', 'integer', 'min:1000'],
            'openai_usd_per_million' => ['nullable', 'numeric', 'min:0.01'],
            'markup_multiplier' => ['nullable', 'numeric', 'min:1'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_highlighted' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $openai = (float) ($payload['openai_usd_per_million'] ?? $this->pricing->defaultOpenAiUsdPerMillion());
        $markup = (float) ($payload['markup_multiplier'] ?? $this->pricing->defaultMarkupMultiplier());
        $priceCents = $this->pricing->calculatePriceCents((int) $payload['monthly_token_limit'], $openai, $markup);

        $plan = Plan::query()->create([
            'slug' => Str::lower($payload['slug']),
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
            'monthly_token_limit' => (int) $payload['monthly_token_limit'],
            'price_cents' => $priceCents,
            'currency' => 'usd',
            'openai_usd_per_million' => $openai,
            'markup_multiplier' => $markup,
            'features' => $payload['features'] ?? [],
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'is_highlighted' => (bool) ($payload['is_highlighted'] ?? false),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ]);

        return response()->json(['status' => 'created', 'plan' => $this->serialize($plan)], 201);
    }

    public function update(Request $request, string $planId): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $plan = Plan::query()->find($planId);
        if (! $plan) {
            return response()->json(['detail' => 'Plan not found'], 404);
        }

        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'monthly_token_limit' => ['sometimes', 'integer', 'min:1000'],
            'openai_usd_per_million' => ['nullable', 'numeric', 'min:0.01'],
            'markup_multiplier' => ['nullable', 'numeric', 'min:1'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_highlighted' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if (array_key_exists('name', $payload)) {
            $plan->name = $payload['name'];
        }
        if (array_key_exists('description', $payload)) {
            $plan->description = $payload['description'];
        }
        if (array_key_exists('features', $payload)) {
            $plan->features = $payload['features'] ?? [];
        }
        if (array_key_exists('is_active', $payload)) {
            $plan->is_active = (bool) $payload['is_active'];
        }
        if (array_key_exists('is_highlighted', $payload)) {
            $plan->is_highlighted = (bool) $payload['is_highlighted'];
        }
        if (array_key_exists('sort_order', $payload)) {
            $plan->sort_order = (int) $payload['sort_order'];
        }

        $tokenLimit = (int) ($payload['monthly_token_limit'] ?? $plan->monthly_token_limit);
        $openai = (float) ($payload['openai_usd_per_million'] ?? $plan->openai_usd_per_million);
        $markup = (float) ($payload['markup_multiplier'] ?? $plan->markup_multiplier);

        $plan->monthly_token_limit = $tokenLimit;
        $plan->openai_usd_per_million = $openai;
        $plan->markup_multiplier = $markup;
        $plan->price_cents = $this->pricing->calculatePriceCents($tokenLimit, $openai, $markup);
        $plan->save();

        return response()->json(['status' => 'updated', 'plan' => $this->serialize($plan)]);
    }

    public function destroy(Request $request, string $planId): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $plan = Plan::query()->find($planId);
        if (! $plan) {
            return response()->json(['detail' => 'Plan not found'], 404);
        }

        $plan->is_active = false;
        $plan->save();

        return response()->json(['status' => 'deactivated', 'plan' => $this->serialize($plan)]);
    }

    public function previewPrice(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->attributes->get('admin');
        if (! $this->isAdminLike($admin)) {
            return response()->json(['detail' => 'Not allowed'], 403);
        }

        $payload = $request->validate([
            'monthly_token_limit' => ['required', 'integer', 'min:1000'],
            'openai_usd_per_million' => ['nullable', 'numeric', 'min:0.01'],
            'markup_multiplier' => ['nullable', 'numeric', 'min:1'],
        ]);

        $openai = (float) ($payload['openai_usd_per_million'] ?? $this->pricing->defaultOpenAiUsdPerMillion());
        $markup = (float) ($payload['markup_multiplier'] ?? $this->pricing->defaultMarkupMultiplier());
        $cents = $this->pricing->calculatePriceCents((int) $payload['monthly_token_limit'], $openai, $markup);

        return response()->json([
            'monthly_token_limit' => (int) $payload['monthly_token_limit'],
            'openai_usd_per_million' => $openai,
            'markup_multiplier' => $markup,
            'price_cents' => $cents,
            'price_display' => '$'.number_format($cents / 100, 2),
            'formula' => 'price = (tokens / 1,000,000) × openai_usd_per_million × markup_multiplier',
        ]);
    }

    private function serialize(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'slug' => $plan->slug,
            'name' => $plan->name,
            'description' => $plan->description,
            'monthly_token_limit' => (int) $plan->monthly_token_limit,
            'price_cents' => (int) $plan->price_cents,
            'price_display' => $plan->formattedPrice(),
            'currency' => $plan->currency,
            'openai_usd_per_million' => (float) $plan->openai_usd_per_million,
            'markup_multiplier' => (float) $plan->markup_multiplier,
            'features' => $plan->features ?? [],
            'is_active' => (bool) $plan->is_active,
            'is_highlighted' => (bool) $plan->is_highlighted,
            'sort_order' => (int) $plan->sort_order,
        ];
    }

    private function isAdminLike(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin'], true);
    }
}
