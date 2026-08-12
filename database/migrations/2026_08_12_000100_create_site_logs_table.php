<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('severity', 32)->default('error'); // debug|info|warning|error|critical
            $table->string('source', 32)->default('laravel'); // laravel|python|frontend|chat|admin|user|system
            $table->string('category', 64)->nullable();
            $table->text('message');
            $table->string('exception_class', 255)->nullable();
            $table->longText('stack_trace')->nullable();
            $table->json('context_json')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('user_email')->nullable();
            $table->string('user_role', 32)->nullable();
            $table->string('request_method', 16)->nullable();
            $table->string('request_path', 512)->nullable();
            $table->text('request_url')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['severity', 'created_at']);
            $table->index(['source', 'created_at']);
            $table->index(['category', 'created_at']);
            $table->index(['correlation_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['resolved_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_logs');
    }
};
