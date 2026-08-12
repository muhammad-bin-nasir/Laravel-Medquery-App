<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('help_ticket_id')->nullable()->constrained('help_tickets')->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['help_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_notifications');
    }
};
