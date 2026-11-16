<?php

use App\Actions\GenerationLifecycle;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('requires creation-time rates through webhook and API response paths', function (GenerationStatus $status) {
    config()->set(['services.pixelforge.api_key' => 'pf_payload_test', 'services.pixelforge.webhook_secret' => 'whsec_payload_test']);
    $user = User::factory()->create(['balance_pico' => 1_000_000_000_000, 'reserved_pico' => 500_000_000_000]);
    $generation = Generation::factory()->create(['user_id' => $user->id, 'reserved_pico' => 500_000_000_000]);
    $provider = [
        'id' => 'gen_payload_test', 'status' => $status->value,
        'metadata' => ['local_id' => $generation->id],
        'created_at' => now()->subMinute()->toIso8601String(),
        'completed_at' => $status->isTerminal() ? now()->toIso8601String() : null,
        'usage' => $status === GenerationStatus::Succeeded
            ? ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 200]
            : null,
    ];
    $body = json_encode(['id' => 'evt_payload_test', 'type' => 'generation.'.$status->value, 'data' => ['object' => $provider]], JSON_THROW_ON_ERROR);
    $timestamp = now()->getTimestamp();
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_payload_test');

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $signature,
    ], $body)->assertUnprocessable()->assertJsonValidationErrors(['data.object.rates']);
    expect(fn () => app(GenerationLifecycle::class)->apply($generation, $provider))->toThrow(ValidationException::class);

    expect($user->fresh()->reserved_pico)->toBe(500_000_000_000)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Queued);
    $this->assertDatabaseCount('credit_transactions', 0);
    $this->assertDatabaseCount('webhook_events', 0);
})->with([GenerationStatus::Processing, GenerationStatus::Succeeded, GenerationStatus::Failed]);
