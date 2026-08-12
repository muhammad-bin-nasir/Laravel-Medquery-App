<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Services\PlanPricingService;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $pricing = app(PlanPricingService::class);
        $openai = $pricing->defaultOpenAiUsdPerMillion();
        $markup = $pricing->defaultMarkupMultiplier();

        $defaults = [
            [
                'slug' => 'basic',
                'name' => 'Basic',
                'description' => 'Starter monthly plan for individual nursing study.',
                'monthly_token_limit' => 1_000_000,
                'features' => [
                    '1,000,000 tokens / month',
                    'RAG chat access',
                    'Document-grounded answers',
                    'Email support',
                ],
                'is_highlighted' => false,
                'sort_order' => 1,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'description' => 'Most popular plan for active clinical learners.',
                'monthly_token_limit' => 5_000_000,
                'features' => [
                    '5,000,000 tokens / month',
                    'RAG chat access',
                    'Voice & image questions',
                    'Priority support',
                ],
                'is_highlighted' => true,
                'sort_order' => 2,
            ],
            [
                'slug' => 'premium',
                'name' => 'Premium',
                'description' => 'High-volume plan for heavy study and team prep.',
                'monthly_token_limit' => 20_000_000,
                'features' => [
                    '20,000,000 tokens / month',
                    'RAG chat access',
                    'Voice & image questions',
                    'Priority support',
                    'Highest monthly allowance',
                ],
                'is_highlighted' => false,
                'sort_order' => 3,
            ],
        ];

        foreach ($defaults as $row) {
            $priceCents = $pricing->calculatePriceCents($row['monthly_token_limit'], $openai, $markup);

            Plan::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'monthly_token_limit' => $row['monthly_token_limit'],
                    'price_cents' => $priceCents,
                    'currency' => 'usd',
                    'openai_usd_per_million' => $openai,
                    'markup_multiplier' => $markup,
                    'features' => $row['features'],
                    'is_active' => true,
                    'is_highlighted' => $row['is_highlighted'],
                    'sort_order' => $row['sort_order'],
                ]
            );
        }
    }
}
