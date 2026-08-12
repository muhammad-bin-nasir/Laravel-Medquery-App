<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'display_name')) {
                $table->string('display_name', 120)->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'stripe_customer_id')) {
                $table->string('stripe_customer_id', 120)->nullable()->after('subscription_id');
            }
            if (! Schema::hasColumn('users', 'stripe_payment_method_id')) {
                $table->string('stripe_payment_method_id', 120)->nullable()->after('stripe_customer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['display_name', 'stripe_customer_id', 'stripe_payment_method_id'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
