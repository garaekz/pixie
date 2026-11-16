<?php

use App\Enums\GenerationStatus;
use App\Enums\PixelforgeEventType;
use App\Enums\SubmissionState;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function pixelforgeEvent(array $generation, string $type, string $eventId = 'evt_test_1'): string
{
    return json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => now()->toIso8601String(),
        'data' => ['object' => $generation],
    ], JSON_THROW_ON_ERROR);
}

function pixelforgeSignature(string $payload, int $timestamp): string
{
    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, config('services.pixelforge.webhook_secret'));
}

function succeededProviderGeneration(string $localId): array
{
    return [
        'id' => 'gen_test_1',
        'object' => 'generation',
        'status' => 'succeeded',
        'prompt' => 'a test image',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'metadata' => ['local_id' => $localId],
        'image_url' => 'https://pixelforge.promptedit.dev/images/gen_test_1.png',
        'image_url_expires_at' => now()->addDay()->toIso8601String(),
        'usage' => [
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 200,
            'total_tokens' => 1310,
        ],
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'error' => null,
        'created_at' => now()->subMinute()->toIso8601String(),
        'started_at' => now()->subSeconds(30)->toIso8601String(),
        'completed_at' => now()->toIso8601String(),
    ];
}

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

it('settles a signed successful generation webhook once across duplicate deliveries', function () {
    $user = User::factory()->create(['balance_pico' => 1_000_000_000_000, 'reserved_pico' => 1_000_000_000_000]);
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'id' => (string) Str::uuid(),
        'status' => 'processing',
        'submission_state' => 'accepted',
        'reserved_pico' => 1_000_000_000_000,
    ]);
    $payload = pixelforgeEvent(succeededProviderGeneration($generation->id), 'generation.succeeded');
    $headers = [
        'Pixelforge-Signature' => pixelforgeSignature($payload, now()->timestamp),
        'Pixelforge-Event-Id' => 'evt_test_1',
        'Pixelforge-Event-Type' => 'generation.succeeded',
    ];

    $server = ['HTTP_PIXELFORGE_SIGNATURE' => $headers['Pixelforge-Signature'], 'HTTP_PIXELFORGE_EVENT_ID' => 'evt_test_1', 'HTTP_PIXELFORGE_EVENT_TYPE' => 'generation.succeeded', 'CONTENT_TYPE' => 'application/json'];
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], $server, $payload)->assertNoContent();
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], $server, $payload)->assertNoContent();
    $secondPayload = pixelforgeEvent(succeededProviderGeneration($generation->id), 'generation.succeeded', 'evt_test_2');
    $secondServer = ['HTTP_PIXELFORGE_SIGNATURE' => pixelforgeSignature($secondPayload, now()->timestamp), 'HTTP_PIXELFORGE_EVENT_ID' => 'evt_test_2', 'HTTP_PIXELFORGE_EVENT_TYPE' => 'generation.succeeded', 'CONTENT_TYPE' => 'application/json'];
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], $secondServer, $secondPayload)->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_pico)->toBe(46_550_000_000)
        ->and($generation->user_charge_pico)->toBe(58_187_500_000)
        ->and(WebhookEvent::query()->where('event_id', 'evt_test_1')->count())->toBe(1)
        ->and(WebhookEvent::query()->where('event_id', 'evt_test_2')->count())->toBe(1)
        ->and(CreditTransaction::query()->where('generation_id', $generation->id)->count())->toBe(1);
});

it('records a signed ping webhook once across duplicate deliveries without mutating application state', function () {
    $this->travelTo(now()->startOfSecond());
    Http::preventStrayRequests();
    Queue::fake();

    $user = User::factory()->create([
        'balance_pico' => 5_000_000_000_000,
        'reserved_pico' => 1_000_000_000,
    ]);
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Processing,
        'submission_state' => SubmissionState::Accepted,
        'reserved_pico' => 1_000_000_000,
    ]);
    $userBefore = $user->refresh()->getAttributes();
    $generationBefore = $generation->refresh()->getAttributes();
    $payload = json_encode([
        'id' => 'evt_ping_1',
        'type' => PixelforgeEventType::Ping->value,
        'created_at' => now()->toIso8601String(),
    ], JSON_THROW_ON_ERROR);
    $server = [
        'HTTP_PIXELFORGE_SIGNATURE' => pixelforgeSignature($payload, now()->timestamp),
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], $server, $payload)->assertNoContent();
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], $server, $payload)->assertNoContent();

    $this->assertDatabaseCount('webhook_events', 1);
    $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_ping_1', 'type' => PixelforgeEventType::Ping->value]);
    expect($user->fresh()->getAttributes())->toBe($userBefore);
    expect($generation->fresh()->getAttributes())->toBe($generationBefore);
    $this->assertDatabaseCount('generations', 1);
    $this->assertDatabaseCount('credit_transactions', 0);
    Queue::assertNothingPushed();
});

it('does not regress a succeeded generation on a stale processing webhook', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'id' => (string) Str::uuid(),
        'status' => 'succeeded',
        'provider_id' => 'gen_test_2',
        'provider_cost_pico' => 1_000_000,
        'user_charge_pico' => 1_250_000,
    ]);
    $provider = succeededProviderGeneration($generation->id);
    $provider['id'] = 'gen_test_2';
    $provider['status'] = 'processing';
    $payload = pixelforgeEvent($provider, 'generation.processing', 'evt_test_processing');
    $headers = ['Pixelforge-Signature' => pixelforgeSignature($payload, now()->timestamp)];

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], ['HTTP_PIXELFORGE_SIGNATURE' => $headers['Pixelforge-Signature'], 'CONTENT_TYPE' => 'application/json'], $payload)->assertNoContent();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->fresh()->user_charge_pico)->toBe(1_250_000);
});

it('rejects invalid webhook signatures before mutation', function () {
    $payload = pixelforgeEvent(['id' => 'gen_unknown'], 'generation.processing', 'evt_bad');

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'HTTP_PIXELFORGE_SIGNATURE' => 't='.now()->timestamp.',v1=bad',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertBadRequest();

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('releases the reservation for an accepted failed generation without charging', function () {
    $user = User::factory()->create(['balance_pico' => 1_000_000_000_000, 'reserved_pico' => 1_000_000_000_000]);
    $generation = Generation::factory()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'status' => 'processing',
        'submission_state' => 'accepted',
        'reserved_pico' => 1_000_000_000_000,
    ]);
    $provider = succeededProviderGeneration($generation->id);
    $provider['status'] = 'failed';
    $provider['error'] = ['code' => 'generation_failed', 'message' => 'No image'];
    $payload = pixelforgeEvent($provider, 'generation.failed', 'evt_failed_1');
    $headers = ['Pixelforge-Signature' => pixelforgeSignature($payload, now()->timestamp)];

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], ['HTTP_PIXELFORGE_SIGNATURE' => $headers['Pixelforge-Signature'], 'CONTENT_TYPE' => 'application/json'], $payload)->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->user_charge_pico)->toBe(0)
        ->and($generation->provider_cost_pico)->toBe(0)
        ->and($generation->reserved_pico)->toBe(0)
        ->and($user->fresh()->reserved_pico)->toBe(0);
});

it('matches a webhook delivered before the create response by local id', function () {
    $user = User::factory()->create(['balance_pico' => 1_000_000_000_000, 'reserved_pico' => 1_000_000_000_000]);
    $generation = Generation::factory()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'status' => 'queued',
        'submission_state' => 'pending',
        'reserved_pico' => 1_000_000_000_000,
    ]);
    $provider = succeededProviderGeneration($generation->id);
    $payload = pixelforgeEvent($provider, 'generation.succeeded', 'evt_early_1');
    $headers = ['Pixelforge-Signature' => pixelforgeSignature($payload, now()->timestamp)];

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], ['HTTP_PIXELFORGE_SIGNATURE' => $headers['Pixelforge-Signature'], 'CONTENT_TYPE' => 'application/json'], $payload)->assertNoContent();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->fresh()->provider_id)->toBe('gen_test_1');
});

it('rejects stale and tampered webhook signatures', function () {
    $payload = pixelforgeEvent(['id' => 'gen_unknown'], 'generation.processing', 'evt_old');
    $oldTimestamp = now()->subMinutes(6)->timestamp;

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'HTTP_PIXELFORGE_SIGNATURE' => pixelforgeSignature($payload, $oldTimestamp),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertBadRequest();

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'HTTP_PIXELFORGE_SIGNATURE' => pixelforgeSignature($payload.'tampered', now()->timestamp),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertBadRequest();
});
