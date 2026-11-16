<?php

use App\Enums\SubmissionState;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function pixelforgePricing(): array
{
    return ['rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'], 'tokens' => ['characters_per_text_token' => 4, 'text_token_variance' => 0.15, 'per_reference_image' => 765, 'output' => ['low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420], 'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650], 'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450]], 'reasoning' => ['low' => ['min' => 0, 'max' => 500], 'medium' => ['min' => 200, 'max' => 2000], 'high' => ['min' => 1000, 'max' => 8000]]]];
}

beforeEach(function () {
    config()->set([
        'services.pixelforge.webhook_secret' => 'whsec_test_secret',
        'services.pixelforge.api_key' => 'pf_test_key',
        'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
    ]);
});

it('reserves and submits a generation with a stable idempotency key', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response(pixelforgePricing()),
    ]);
    Queue::fake();

    $user = User::factory()->create(['balance_pico' => 1_000_000_000_000]);

    $response = $this->actingAs($user)->post(route('generations.store'), [
        'request_id' => (string) Str::uuid(),
        'prompt' => 'a lighthouse',
        'size' => '1024x1024',
        'quality' => 'low',
        'reference_images' => [],
    ]);

    $response->assertRedirect(route('dashboard'));
    $generation = Generation::query()->firstOrFail();

    expect($generation->submission_state)->toBe(SubmissionState::Pending)
        ->and($generation->reserved_pico)->toBeGreaterThan(0);
    expect($user->fresh()->reserved_pico)->toBe($generation->reserved_pico);

    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job): bool => $job->generationId === $generation->id);
});

it('credits a purchase only once for the same request id', function () {
    $user = User::factory()->create();
    $requestId = (string) Str::uuid();

    $this->actingAs($user)->post(route('credits.store'), ['request_id' => $requestId])->assertRedirect();
    $this->actingAs($user)->post(route('credits.store'), ['request_id' => $requestId])->assertRedirect();

    expect($user->fresh()->balance_pico)->toBe(5_000_000_000_000)
        ->and(CreditTransaction::query()->where('user_id', $user->id)->where('type', 'purchase')->count())->toBe(1);
});

it('does not reserve or queue twice for a repeated generation request id', function () {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
            ...pixelforgePricing(),
        ]),
    ]);
    Queue::fake();
    $user = User::factory()->create(['balance_pico' => 1_000_000_000_000]);
    $requestId = (string) Str::uuid();
    $data = ['request_id' => $requestId, 'prompt' => 'a lighthouse', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];

    $this->actingAs($user)->post(route('generations.store'), $data)->assertRedirect();
    $this->actingAs($user)->post(route('generations.store'), $data)->assertRedirect();

    expect(Generation::query()->whereKey($requestId)->count())->toBe(1);
    Queue::assertPushed(SubmitGeneration::class, 1);
});
