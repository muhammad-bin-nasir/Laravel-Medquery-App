<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('subject', 255);
            $table->text('message');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->string('status', 32)->default('open'); // open | answered | closed
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('help_ticket_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('help_ticket_id')->constrained('help_tickets')->cascadeOnDelete();
            $table->foreignUuid('admin_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();

            $table->index(['help_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_ticket_replies');
        Schema::dropIfExists('help_tickets');
    }
};
