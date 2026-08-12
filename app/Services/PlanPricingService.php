<?php

namespace App\Services;

class PlanPricingService
{
    /**
     * Price = OpenAI cost × markup.
     * Example: 1M tokens at $1/M OpenAI → $3 charged (3× markup).
     */
    public function calculatePriceCents(
        int $monthlyTokenLimit,
        float $openaiUsdPerMillion = 1.0,
        float $markupMultiplier = 3.0,
    ): int {
        $millions = max(0, $monthlyTokenLimit) / 1_000_000;
        $usd = $millions * max(0.0, $openaiUsdPerMillion) * max(1.0, $markupMultiplier);

        return (int) max(1, (int) round($usd * 100));
    }

    public function defaultOpenAiUsdPerMillion(): float
    {
        return (float) config('services.billing.openai_usd_per_million', 1.0);
    }

    public function defaultMarkupMultiplier(): float
    {
        return (float) config('services.billing.markup_multiplier', 3.0);
    }
}
