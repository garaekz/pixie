<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider_id')->nullable()->unique();
            $table->char('api_key_hash', 64);
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');
            $table->string('status')->default('queued');
            $table->string('submission_state')->default('pending');
            $table->unsignedInteger('submission_attempts')->default(0);
            $table->boolean('submission_uncertain')->default(false);
            $table->timestamp('next_attempt_at')->nullable();
            $table->bigInteger('estimate_pico');
            $table->bigInteger('reserved_pico');
            $table->bigInteger('provider_cost_pico')->nullable();
            $table->bigInteger('user_charge_pico')->nullable();
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['api_key_hash', 'completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
