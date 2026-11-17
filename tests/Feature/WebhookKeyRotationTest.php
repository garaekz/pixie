<?php

use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
});

function rotationConfigureCredentials(?string $previousApiKey = 'pf_old_key', ?string $previousSecret = 'whsec_old_secret'): void
{
    config()->set([
        'services.pixelforge.api_key' => 'pf_new_key',
        'services.pixelforge.webhook_secret' => 'whsec_new_secret',
        'services.pixelforge.previous_api_key' => $previousApiKey,
        'services.pixelforge.previous_webhook_secret' => $previousSecret,
    ]);
}

function rotationGenerationPayload(string $localId, string $providerId, string $status = 'succeeded'): array
{
    $now = now();

    return [
        'id' => $providerId, 'object' => 'generation', 'status' => $status,
        'metadata' => ['local_id' => $localId],
        'created_at' => $now->copy()->subMinute()->toIso8601String(),
        'started_at' => $now->copy()->subSeconds(30)->toIso8601String(),
        'completed_at' => $status === 'succeeded' ? $now->toIso8601String() : null,
        'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/images/'.$providerId.'.png' : null,
        'image_url_expires_at' => $now->copy()->addDay()->toIso8601String(),
        'usage' => $status === 'succeeded' ? ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 200, 'total_tokens' => 1310] : null,
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'error' => null,
    ];
}

function rotationEvent(array $provider, string $eventId = 'evt_rotation'): string
{
    return json_encode(['id' => $eventId, 'object' => 'event', 'type' => 'generation.'.$provider['status'], 'created_at' => now()->toIso8601String(), 'data' => ['object' => $provider]], JSON_THROW_ON_ERROR);
}

function rotationPing(string $eventId = 'evt_ping_rotation'): string
{
    return json_encode(['id' => $eventId, 'object' => 'event', 'type' => 'ping', 'created_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR);
}

function rotationSignature(string $body, string $secret): string
{
    $timestamp = now()->timestamp;

    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
}

function rotationGeneration(string $apiKeyHash, User $user): Generation
{
    return Generation::factory()->create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'api_key_hash' => $apiKeyHash, 'reserved_pico' => 500_000_000_000]);
}

it('settles an old-key generation after rotation and deduplicates its webhook', function () {
    rotationConfigureCredentials();
    $user = User::factory()->create(['balance_pico' => 1_000_000_000_000, 'reserved_pico' => 500_000_000_000]);
    $generation = rotationGeneration(hash('sha256', 'pf_old_key'), $user);
    $body = rotationEvent(rotationGenerationPayload($generation->id, 'gen_old_rotation'));
    $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => rotationSignature($body, 'whsec_old_secret')];

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], $headers, $body)->assertNoContent();
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], $headers, $body)->assertNoContent();
    $generationBefore = $generation->fresh()->toArray();
    $userBefore = $user->fresh()->toArray();
    $currentHeaders = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => rotationSignature($body, 'whsec_new_secret')];
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], $currentHeaders, $body)->assertStatus(409);

    expect($generation->fresh()->toArray())->toEqual($generationBefore)
        ->and($user->fresh()->toArray())->toEqual($userBefore)
        ->and(CreditTransaction::query()->where('generation_id', $generation->id)->count())->toBe(1)
        ->and(WebhookEvent::query()->where('event_id', 'evt_rotation')->count())->toBe(1);
});

it('rejects a valid credential when it does not match the generation key without mutation', function () {
    rotationConfigureCredentials();
    $newUser = User::factory()->create(['balance_pico' => 1_000_000_000_000, 'reserved_pico' => 500_000_000_000]);
    $oldUser = User::factory()->create(['balance_pico' => 1_000_000_000_000, 'reserved_pico' => 500_000_000_000]);
    $oldGeneration = rotationGeneration(hash('sha256', 'pf_old_key'), $oldUser);
    $newGeneration = rotationGeneration(hash('sha256', 'pf_new_key'), $newUser);
    $oldGenerationBefore = $oldGeneration->fresh()->toArray();
    $newGenerationBefore = $newGeneration->fresh()->toArray();
    $oldUserBefore = $oldUser->fresh()->toArray();
    $newUserBefore = $newUser->fresh()->toArray();
    $oldBody = rotationEvent(rotationGenerationPayload($oldGeneration->id, 'gen_cross_old'), 'evt_cross_old');
    $newBody = rotationEvent(rotationGenerationPayload($newGeneration->id, 'gen_cross_new'), 'evt_cross_new');

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => rotationSignature($oldBody, 'whsec_new_secret')], $oldBody)->assertStatus(409);
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => rotationSignature($newBody, 'whsec_old_secret')], $newBody)->assertStatus(409);

    expect($oldGeneration->fresh()->toArray())->toEqual($oldGenerationBefore)
        ->and($newGeneration->fresh()->toArray())->toEqual($newGenerationBefore)
        ->and($oldUser->fresh()->toArray())->toEqual($oldUserBefore)
        ->and($newUser->fresh()->toArray())->toEqual($newUserBefore)
        ->and(CreditTransaction::query()->count())->toBe(0)
        ->and(WebhookEvent::query()->count())->toBe(0);
});

it('does not poison a reused event id after a cross-key rejection', function () {
    rotationConfigureCredentials();
    $user = User::factory()->create(['balance_pico' => 1_000_000_000_000, 'reserved_pico' => 500_000_000_000]);
    $generation = rotationGeneration(hash('sha256', 'pf_old_key'), $user);
    $body = rotationEvent(rotationGenerationPayload($generation->id, 'gen_reused_event'), 'evt_reused_rotation');

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => rotationSignature($body, 'whsec_new_secret')], $body)->assertStatus(409);
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => rotationSignature($body, 'whsec_old_secret')], $body)->assertNoContent();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and(WebhookEvent::query()->where('event_id', 'evt_reused_rotation')->count())->toBe(1)
        ->and(CreditTransaction::query()->where('generation_id', $generation->id)->count())->toBe(1);
});

it('rejects a removed or unpaired previous secret', function (?string $previousApiKey, ?string $previousSecret) {
    rotationConfigureCredentials($previousApiKey, $previousSecret);
    $body = rotationPing();

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => rotationSignature($body, 'whsec_old_secret')], $body)->assertBadRequest();
    expect(WebhookEvent::query()->count())->toBe(0);
})->with([
    ['pf_old_key', null],
    [null, 'whsec_old_secret'],
    [null, null],
]);

it('accepts a previous ping once without wallet effects', function () {
    rotationConfigureCredentials();
    $body = rotationPing();
    $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => rotationSignature($body, 'whsec_old_secret')];

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], $headers, $body)->assertNoContent();
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], $headers, $body)->assertNoContent();

    expect(WebhookEvent::query()->where('event_id', 'evt_ping_rotation')->count())->toBe(1)
        ->and(Generation::query()->count())->toBe(0)
        ->and(CreditTransaction::query()->count())->toBe(0);
});

it('fails closed when the same webhook secret belongs to different api keys', function () {
    rotationConfigureCredentials('pf_old_key', 'whsec_new_secret');
    $body = rotationPing();

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => rotationSignature($body, 'whsec_new_secret')], $body)->assertStatus(503);
});

it('accepts a previous webhook secret when the api key is unchanged', function () {
    rotationConfigureCredentials('pf_new_key', 'whsec_old_secret');
    $oldBody = rotationPing('evt_shared_key_old');
    $newBody = rotationPing('evt_shared_key_new');

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => rotationSignature($oldBody, 'whsec_old_secret')], $oldBody)->assertNoContent();
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => rotationSignature($newBody, 'whsec_new_secret')], $newBody)->assertNoContent();

    expect(WebhookEvent::query()->whereIn('event_id', ['evt_shared_key_old', 'evt_shared_key_new'])->count())->toBe(2);
});
