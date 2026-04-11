<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('business_id')->references('id')->on('businesses')->nullOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->foreign('admin_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropForeign(['admin_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['business_id']);
            $table->dropForeign(['workspace_id']);
        });
    }
};
