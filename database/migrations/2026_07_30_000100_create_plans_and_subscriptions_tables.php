<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('monthly_token_limit');
            $table->unsignedInteger('price_cents');
            $table->string('currency', 10)->default('usd');
            $table->decimal('openai_usd_per_million', 10, 4)->default(1);
            $table->decimal('markup_multiplier', 6, 2)->default(3);
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_highlighted')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('plan_id');
            $table->string('status', 30)->default('active');
            $table->string('stripe_payment_intent_id', 255)->nullable();
            $table->unsignedBigInteger('tokens_included');
            $table->unsignedBigInteger('tokens_used')->default(0);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 10)->default('usd');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
            $table->index(['user_id', 'status']);
            $table->index('current_period_end');
        });

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'subscription_id')) {
                $table->uuid('subscription_id')->nullable()->after('plan');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'subscription_id')) {
                $table->dropColumn('subscription_id');
            }
        });

        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
