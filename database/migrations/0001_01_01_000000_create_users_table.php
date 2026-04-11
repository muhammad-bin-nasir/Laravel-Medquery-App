<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('business_id')->nullable()->index();
            $table->uuid('workspace_id')->nullable()->index();
            $table->string('email', 255);
            $table->string('email_normalized', 255)->index();
            $table->string('password_hash', 255);
            $table->string('role', 50)->default('admin');
            $table->timestamps();

            $table->unique(['business_id', 'email_normalized'], 'uq_admin_business_email_normalized');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
