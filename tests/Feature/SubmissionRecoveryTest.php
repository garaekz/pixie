<?php

use App\Actions\GenerationLifecycle;
use App\Actions\PixelforgeClient;
use App\Enums\GenerationStatus;
use App\Enums\SubmissionState;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function submissionProvider(string $id, string $status = 'queued'): array
{
    return [
        'id' => $id,
        'status' => $status,
        'created_at' => now()->toIso8601String(),
        'started_at' => null,
        'completed_at' => null,
        'image_url' => null,
        'image_url_expires_at' => null,
        'rates' => [
            'input_text_tokens' => '5.00', 'input_image_tokens' => '10.00',
            'output_tokens' => '40.00', 'reasoning_tokens' => '12.50',
        ],
    ];
}

function successfulSubmissionProvider(string $id): array
{
    return array_merge(submissionProvider($id, 'succeeded'), [
        'completed_at' => now()->toIso8601String(),
        'usage' => ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 0],
    ]);
}

beforeEach(function () {
    config()->set([
        'services.pixelforge.api_key' => 'pf_test_key',
        'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
    ]);
});

function pendingGeneration(array $overrides = []): Generation
{
    $user = User::factory()->create(['balance_pico' => 10_000_000_000_000, 'reserved_pico' => 1_000_000_000]);

    return Generation::factory()->create(array_merge([
        'user_id' => $user->id,
        'api_key_hash' => PixelforgeClient::keyHash(),
        'prompt' => 'a test image',
        'size' => '1024x1024',
        'quality' => 'low',
        'reference_images' => [],
        'status' => 'queued',
        'submission_state' => 'pending',
        'submission_attempts' => 0,
        'next_attempt_at' => now(),
        'estimate_pico' => 1_000_000_000,
        'reserved_pico' => 1_000_000_000,
    ], $overrides));
}

it('submits with a stable idempotency key and applies the provider response', function () {
    Http::preventStrayRequests();
    $generation = pendingGeneration();
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(submissionProvider('gen_test_1')),
    ]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    Http::assertSent(fn ($request): bool => $request->header('Idempotency-Key')[0] === $generation->id);
    expect($generation->fresh()->provider_id)->toBe('gen_test_1')
        ->and($generation->fresh()->submission_state)->toBe(SubmissionState::Accepted);
});

it('retries a transient rejection with a bounded delayed job', function () {
    Http::preventStrayRequests();
    $generation = pendingGeneration();
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '7']),
    ]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    expect($generation->fresh()->submission_attempts)->toBe(1)
        ->and($generation->fresh()->next_attempt_at)->not->toBeNull();
    expect($generation->fresh()->error)->toBe('Pixelforge is temporarily unavailable; retrying.');
});

it('preserves the reservation when the submission outcome is ambiguous', function () {
    Http::preventStrayRequests();
    $generation = pendingGeneration();
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::failedConnection(),
    ]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    expect($generation->fresh()->submission_state)->toBe(SubmissionState::Pending)
        ->and($generation->fresh()->reserved_pico)->toBe(1_000_000_000)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Queued);
});

it('recovers an expired submission lease conservatively before a definite rejection', function () {
    Http::preventStrayRequests();
    $generation = pendingGeneration([
        'submission_attempts' => 1,
        'next_attempt_at' => now()->subMinute(),
        'error' => null,
    ]);
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(['error' => ['code' => 'validation_failed', 'message' => 'Rejected']], 422),
    ]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    expect($generation->fresh()->submission_uncertain)->toBeTrue()
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->fresh()->reserved_pico)->toBe(1_000_000_000);
});

it('keeps the accepted reservation on transient provider polling failure', function () {
    Exceptions::fake();
    Http::preventStrayRequests();
    $generation = pendingGeneration([
        'provider_id' => 'gen_test_2',
        'status' => 'processing',
        'submission_state' => 'accepted',
        'next_attempt_at' => now(),
    ]);
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/gen_test_2' => Http::failedConnection(),
    ]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    expect($generation->fresh()->submission_state)->toBe(SubmissionState::Accepted)
        ->and($generation->fresh()->next_attempt_at)->not->toBeNull()
        ->and($generation->fresh()->reserved_pico)->toBe(1_000_000_000);
    Exceptions::assertNothingReported();
});

it('stops after five ambiguous attempts and keeps the reservation held', function () {
    Http::preventStrayRequests();
    $generation = pendingGeneration([
        'submission_attempts' => 5,
        'next_attempt_at' => now()->subMinute(),
        'error' => null,
    ]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    expect($generation->fresh()->submission_state)->toBe(SubmissionState::Uncertain)
        ->and($generation->fresh()->submission_uncertain)->toBeTrue()
        ->and($generation->fresh()->reserved_pico)->toBe(1_000_000_000)
        ->and($generation->fresh()->next_attempt_at)->toBeNull();
});

it('retains funds when a fifth transient rejection follows an ambiguous attempt', function () {
    Http::preventStrayRequests();
    $generation = pendingGeneration([
        'submission_uncertain' => true,
        'submission_attempts' => 4,
        'next_attempt_at' => now()->subMinute(),
        'error' => 'Previous submission outcome was ambiguous.',
    ]);
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(['error' => ['code' => 'overloaded']], 503),
    ]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    expect($generation->fresh()->submission_state)->toBe(SubmissionState::Uncertain)
        ->and($generation->fresh()->reserved_pico)->toBe(1_000_000_000)
        ->and($generation->fresh()->user->reserved_pico)->toBe(1_000_000_000);
});

it('reports a generic submission exception while preserving recovery state', function () {
    Exceptions::fake();
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => fn () => throw new RuntimeException('unexpected transport bug'),
    ]);
    $generation = pendingGeneration();

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    Exceptions::assertReported(RuntimeException::class);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->fresh()->reserved_pico)->toBe(1_000_000_000);
});

it('reports a lifecycle invariant failure without charging', function () {
    Exceptions::fake();
    $generation = pendingGeneration();
    $generation->user->forceFill(['reserved_pico' => 0])->save();
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(successfulSubmissionProvider('gen_invariant')),
    ]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    Exceptions::assertReported(LogicException::class);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->fresh()->user_charge_pico)->toBeNull()
        ->and($generation->fresh()->reserved_pico)->toBe(1_000_000_000);
});

it('wraps and reports malformed successful provider payloads', function () {
    Exceptions::fake();
    $generation = pendingGeneration();
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(['id' => 'gen_invalid', 'status' => 'succeeded']),
    ]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    Exceptions::assertReported(fn (UnexpectedValueException $exception): bool => $exception->getPrevious() instanceof ValidationException);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->fresh()->user_charge_pico)->toBeNull()
        ->and($generation->fresh()->reserved_pico)->toBe(1_000_000_000);
});

it('resets a non-terminal submission on manual retry without changing its id', function () {
    Queue::fake();
    $generation = pendingGeneration(['submission_state' => 'uncertain', 'submission_attempts' => 5, 'next_attempt_at' => null]);

    $this->artisan('pixelforge:retry', ['generation' => $generation->id])->assertSuccessful();

    expect($generation->fresh()->submission_attempts)->toBe(0)
        ->and($generation->fresh()->submission_state)->toBe(SubmissionState::Pending);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job): bool => $job->generationId === $generation->id);
});

it('returns 422 without changing a generation or wallet when retry is scheduled in the future', function (SubmissionState $state) {
    $this->travelTo(now()->startOfSecond());
    Queue::fake([SubmitGeneration::class]);
    $generation = pendingGeneration([
        'submission_state' => $state,
        'submission_uncertain' => $state === SubmissionState::Uncertain,
        'submission_attempts' => 3,
        'next_attempt_at' => now()->addMinutes(10),
        'error' => 'Pixelforge is temporarily unavailable; retrying.',
        'provider_id' => $state === SubmissionState::Accepted ? 'gen_waiting' : null,
    ]);
    $user = $generation->user;
    $generationBefore = $generation->refresh()->getAttributes();
    $walletBefore = $user->getAttributes();

    $this->actingAs($user)->postJson(route('generations.retry', $generation))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('prompt');

    expect($generation->fresh()->getAttributes())->toBe($generationBefore);
    expect($user->fresh()->getAttributes())->toBe($walletBefore);
    Queue::assertNothingPushed();
})->with([SubmissionState::Pending, SubmissionState::Accepted, SubmissionState::Uncertain]);

it('allows manual recovery once the retry deadline is reached', function () {
    $this->travelTo(now()->startOfSecond());
    Queue::fake([SubmitGeneration::class]);
    $generation = pendingGeneration([
        'submission_state' => SubmissionState::Uncertain,
        'submission_uncertain' => true,
        'submission_attempts' => 5,
        'next_attempt_at' => now(),
    ]);
    $user = $generation->user;
    $walletBefore = $user->getAttributes();

    $this->actingAs($user)->from(route('generations.show', $generation))
        ->post(route('generations.retry', $generation))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('generations.show', $generation));

    $generation->refresh();
    expect($generation->submission_attempts)->toBe(0);
    expect($generation->submission_state)->toBe(SubmissionState::Pending);
    expect($generation->submission_uncertain)->toBeTrue();
    expect($generation->next_attempt_at->equalTo(now()))->toBeTrue();
    expect($generation->reserved_pico)->toBe(1_000_000_000);
    expect($user->fresh()->getAttributes())->toBe($walletBefore);
    Queue::assertPushed(SubmitGeneration::class, 1);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job): bool => $job->generationId === $generation->id);
});

it('reports validation diagnostics for malformed polled payloads', function () {
    Http::preventStrayRequests();
    Exceptions::fake();
    $generation = pendingGeneration(['provider_id' => 'gen_get_bad', 'submission_state' => SubmissionState::Accepted]);
    $provider = submissionProvider('gen_get_bad', 'succeeded');
    $provider['completed_at'] = now()->toIso8601String();
    $provider['usage'] = ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 200];
    unset($provider['rates']);
    Http::fake(['pixelforge.promptedit.dev/v1/generations/gen_get_bad' => Http::response($provider)]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    Exceptions::assertReported(fn (UnexpectedValueException $exception): bool => $exception->getPrevious() instanceof ValidationException);
    expect($generation->fresh()->reserved_pico)->toBe(1_000_000_000)
        ->and($generation->fresh()->next_attempt_at)->not->toBeNull();
    $this->assertDatabaseCount('credit_transactions', 0);
});

it('distinguishes transient provider responses from configuration errors when polling', function (int $status, bool $shouldReport) {
    Exceptions::fake();
    Http::preventStrayRequests();
    $generation = pendingGeneration(['provider_id' => 'gen_http_error', 'submission_state' => SubmissionState::Accepted]);
    Http::fake(['pixelforge.promptedit.dev/v1/generations/gen_http_error' => Http::response(['error' => ['message' => 'Provider error']], $status)]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class));

    if ($shouldReport) {
        Exceptions::assertReported(RequestException::class);
    } else {
        Exceptions::assertNothingReported();
    }
    expect($generation->fresh()->reserved_pico)->toBe(1_000_000_000)
        ->and($generation->fresh()->next_attempt_at)->not->toBeNull();
})->with([[429, false], [503, false], [401, true]]);
