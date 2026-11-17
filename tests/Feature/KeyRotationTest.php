<?php

use App\Actions\GenerationLifecycle;
use App\Actions\PixelforgeClient;
use App\Actions\PixelforgeCredentials;
use App\Enums\GenerationStatus;
use App\Enums\SubmissionState;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function keyRotationProvider(string $id, string $status = 'succeeded'): array
{
    return [
        'id' => $id, 'status' => $status,
        'created_at' => now()->subMinute()->toIso8601String(), 'started_at' => now()->subSeconds(30)->toIso8601String(),
        'completed_at' => $status === 'succeeded' ? now()->toIso8601String() : null,
        'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/images/'.$id.'.png' : null,
        'image_url_expires_at' => $status === 'succeeded' ? now()->addDay()->toIso8601String() : null,
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'usage' => $status === 'succeeded' ? ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 0] : null,
    ];
}

function keyRotationGeneration(string $apiKeyHash, array $overrides = []): Generation
{
    $user = User::factory()->create(['balance_pico' => 10_000_000_000_000, 'reserved_pico' => 1_000_000_000]);

    return Generation::factory()->create(array_merge([
        'user_id' => $user->id, 'api_key_hash' => $apiKeyHash, 'status' => GenerationStatus::Queued,
        'submission_state' => SubmissionState::Pending, 'submission_attempts' => 0, 'next_attempt_at' => now(),
        'estimate_pico' => 1_000_000_000, 'reserved_pico' => 1_000_000_000,
    ], $overrides));
}

beforeEach(function () {
    config()->set([
        'services.pixelforge.api_key' => 'pf_current_key', 'services.pixelforge.previous_api_key' => 'pf_previous_key',
        'services.pixelforge.webhook_secret' => 'whsec_current', 'services.pixelforge.previous_webhook_secret' => 'whsec_previous',
        'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
    ]);
    Http::preventStrayRequests();
});

it('submits an old pending generation with its original bearer and idempotency key', function () {
    Http::fake(['pixelforge.promptedit.dev/v1/generations' => Http::response(keyRotationProvider('gen_previous'))]);
    $generation = keyRotationGeneration(hash('sha256', 'pf_previous_key'));

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    Http::assertSent(fn ($request): bool => $request->header('Authorization') === ['Bearer pf_previous_key'] && $request->header('Idempotency-Key') === [$generation->id]);
    Http::assertSentCount(1);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->fresh()->reserved_pico)->toBe(0)
        ->and(CreditTransaction::query()->where('generation_id', $generation->id)->count())->toBe(1);
});

it('polls an accepted old generation with its original bearer', function () {
    Http::fake(['pixelforge.promptedit.dev/v1/generations/gen_previous' => Http::response(keyRotationProvider('gen_previous'))]);
    $generation = keyRotationGeneration(hash('sha256', 'pf_previous_key'), ['provider_id' => 'gen_previous', 'status' => GenerationStatus::Processing, 'submission_state' => SubmissionState::Accepted]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    Http::assertSent(fn ($request): bool => $request->header('Authorization') === ['Bearer pf_previous_key']);
    Http::assertSentCount(1);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('reconciles due generations for active and previous keys but excludes unknown credentials', function () {
    Queue::fake();
    $current = keyRotationGeneration(PixelforgeClient::keyHash());
    $previous = keyRotationGeneration(hash('sha256', 'pf_previous_key'));
    keyRotationGeneration(hash('sha256', 'pf_retired_key'));

    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    Queue::assertPushed(SubmitGeneration::class, 2);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job): bool => $job->generationId === $current->id);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job): bool => $job->generationId === $previous->id);
});

it('allows an owner to manually retry an old-key generation without changing its identity', function () {
    Queue::fake();
    $generation = keyRotationGeneration(hash('sha256', 'pf_previous_key'), ['submission_state' => SubmissionState::Uncertain, 'submission_uncertain' => true, 'submission_attempts' => 5, 'next_attempt_at' => null]);

    $this->actingAs($generation->user)->post(route('generations.retry', $generation))->assertRedirect();

    expect($generation->fresh()->api_key_hash)->toBe(hash('sha256', 'pf_previous_key'))->and($generation->fresh()->submission_attempts)->toBe(0);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job): bool => $job->generationId === $generation->id);
});

it('rejects manual retry when the original credential is unavailable without mutation', function () {
    Queue::fake();
    config()->set('services.pixelforge.previous_api_key', null);
    $generation = keyRotationGeneration(hash('sha256', 'pf_previous_key'), ['submission_state' => SubmissionState::Uncertain, 'submission_uncertain' => true, 'submission_attempts' => 5, 'next_attempt_at' => null]);
    $before = $generation->refresh()->getAttributes();
    $walletBefore = $generation->user->getAttributes();

    $this->actingAs($generation->user)->postJson(route('generations.retry', $generation))->assertUnprocessable()->assertJsonValidationErrors('prompt');

    expect($generation->fresh()->getAttributes())->toBe($before)->and($generation->user->fresh()->getAttributes())->toBe($walletBefore);
    Queue::assertNothingPushed();
    expect(PixelforgeCredentials::apiKey($generation->api_key_hash))->toBeNull();
});

it('does not silently fall back when a queued generation has no original credential', function (?string $providerId) {
    config()->set('services.pixelforge.previous_api_key', null);
    $generation = keyRotationGeneration(hash('sha256', 'pf_previous_key'), [
        'provider_id' => $providerId,
        'status' => $providerId === null ? GenerationStatus::Queued : GenerationStatus::Processing,
        'submission_state' => $providerId === null ? SubmissionState::Pending : SubmissionState::Accepted,
    ]);
    $before = $generation->refresh()->getAttributes();
    $walletBefore = $generation->user->getAttributes();

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    expect($generation->fresh()->getAttributes())->toBe($before)
        ->and($generation->user->fresh()->getAttributes())->toBe($walletBefore);
    Http::assertNothingSent();

    if ($providerId !== null) {
        expect(fn () => app(PixelforgeClient::class)->retrieve($generation))->toThrow(LogicException::class);
    } else {
        expect(fn () => app(PixelforgeClient::class)->submit($generation))->toThrow(LogicException::class);
    }
})->with([null, 'gen_previous']);

it('always reserves new generations against the active credential', function () {
    Queue::fake();
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response([
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'tokens' => ['characters_per_text_token' => 4, 'text_token_variance' => 0.15, 'per_reference_image' => 765, 'output' => ['low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420], 'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650], 'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450]], 'reasoning' => ['low' => ['min' => 0, 'max' => 500], 'medium' => ['min' => 200, 'max' => 2000], 'high' => ['min' => 1000, 'max' => 8000]]],
    ])]);
    $user = User::factory()->create(['balance_pico' => 10_000_000_000_000]);

    $this->actingAs($user)->post(route('generations.store'), ['request_id' => (string) Str::uuid(), 'prompt' => 'new image', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []])->assertRedirect();

    expect(Generation::query()->latest('created_at')->firstOrFail()->api_key_hash)->toBe(PixelforgeClient::keyHash());
});
