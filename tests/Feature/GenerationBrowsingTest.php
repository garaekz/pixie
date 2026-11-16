<?php

use App\Enums\GenerationStatus;
use App\Enums\SubmissionState;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

it('filters generation lists by status and retains the filter in pagination links', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Generation::factory()->count(13)->create(['user_id' => $user->id, 'status' => GenerationStatus::Succeeded]);
    Generation::factory()->create(['user_id' => $user->id, 'status' => GenerationStatus::Failed]);
    Generation::factory()->create(['user_id' => $other->id, 'status' => GenerationStatus::Succeeded]);

    $response = $this->actingAs($user)->get(route('generations.index', ['status' => GenerationStatus::Succeeded->value]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Generations/Index')
        ->where('filters.status', GenerationStatus::Succeeded->value)
        ->where('generations.data', fn ($rows): bool => $rows->count() === 12 && $rows->every(fn (array $row): bool => $row['status'] === GenerationStatus::Succeeded->value))
        ->where('generations.total', 13)
        ->where('generations.links', fn ($links): bool => collect($links)->contains(fn (array $link): bool => str_contains((string) $link['url'], 'status=succeeded'))));

    $this->actingAs($user)->get(route('generations.index', ['status' => GenerationStatus::Succeeded->value, 'page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->where('generations.current_page', 2)
            ->where('generations.data', fn ($rows): bool => $rows->count() === 1 && $rows[0]['status'] === GenerationStatus::Succeeded->value));

    $this->actingAs($user)->get(route('dashboard', ['status' => GenerationStatus::Failed->value]))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('filters.status', GenerationStatus::Failed->value)
            ->has('generations.data', 1));
});

it('shows only the latest six matching dashboard generations without pagination', function () {
    $user = User::factory()->create();
    $matching = collect(range(1, 9))->map(fn (int $index): Generation => Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Succeeded,
        'created_at' => now()->subMinutes($index + 10),
    ]));
    $newer = collect(range(1, 8))->map(fn (int $index): Generation => Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Failed,
        'created_at' => now()->subMinutes($index),
    ]));
    Generation::factory()->create(['status' => GenerationStatus::Succeeded, 'created_at' => now()]);

    $this->actingAs($user)->get(route('dashboard', ['page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->has('generations.data', 6)
            ->where('generations.data', fn ($rows): bool => $rows->pluck('id')->all() === $newer->take(6)->pluck('id')->all())
            ->missing('generations.links')
            ->missing('generations.current_page')
            ->missing('generations.total'));

    $this->get(route('dashboard', ['status' => GenerationStatus::Succeeded->value, 'page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.status', GenerationStatus::Succeeded->value)
            ->has('generations.data', 6)
            ->where('generations.data', fn ($rows): bool => $rows->pluck('id')->all() === $matching->take(6)->pluck('id')->all())
            ->missing('generations.links'));

    $this->get(route('dashboard', ['status' => GenerationStatus::Processing->value]))
        ->assertInertia(fn ($page) => $page
            ->has('generations.data', 0)
            ->missing('generations.links'));
});

it('exposes user metadata without provider accounting or identity in generation details', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Succeeded,
        'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
        'provider_id' => 'provider-secret',
        'provider_cost_pico' => 10,
        'rates' => ['image' => '1'],
    ]);

    $response = $this->actingAs($user)->get(route('generations.show', $generation));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Generations/Show')
        ->where('generation.id', $generation->id)
        ->where('generation.usage.output_tokens', 3)
        ->missing('generation.provider_id')
        ->missing('generation.provider_cost')
        ->missing('generation.rates'));
});

it('returns 404 when another user views generation details', function () {
    $owner = User::factory()->create();
    $generation = Generation::factory()->create(['user_id' => $owner->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('generations.show', $generation))
        ->assertNotFound();
});

it('returns 404 without changing state or dispatching jobs when another user retries a generation', function () {
    Queue::fake();
    $owner = User::factory()->create(['balance_pico' => 5_000_000_000_000, 'reserved_pico' => 1_000_000_000]);
    $otherUser = User::factory()->create();
    $generation = Generation::factory()->create([
        'user_id' => $owner->id,
        'submission_state' => SubmissionState::Uncertain,
        'submission_uncertain' => true,
        'submission_attempts' => 5,
        'next_attempt_at' => null,
        'reserved_pico' => 1_000_000_000,
    ]);
    $generationBefore = $generation->refresh()->getAttributes();
    $ownerBefore = $owner->refresh()->getAttributes();
    $otherUserBefore = $otherUser->refresh()->getAttributes();

    $this->actingAs($otherUser)->postJson(route('generations.retry', $generation))
        ->assertNotFound();

    expect($generation->fresh()->getAttributes())->toBe($generationBefore);
    expect($owner->fresh()->getAttributes())->toBe($ownerBefore);
    expect($otherUser->fresh()->getAttributes())->toBe($otherUserBefore);
    $this->assertDatabaseCount('generations', 1);
    $this->assertDatabaseCount('credit_transactions', 0);
    Queue::assertNothingPushed();
});

it('rejects unknown generation status filters', function () {
    $user = User::factory()->create();
    $invalid = Str::random(8);

    $this->actingAs($user)->get(route('dashboard', ['status' => $invalid]))
        ->assertRedirect()->assertSessionHasErrors('status');
    $this->actingAs($user)->get(route('generations.index', ['status' => $invalid]))
        ->assertRedirect()->assertSessionHasErrors('status');
});

it('redirects guests attempting to browse generation lists or details', function () {
    $generation = Generation::factory()->create();

    $this->get(route('generations.index'))->assertRedirect(route('login'));
    $this->get(route('generations.show', $generation))->assertRedirect(route('login'));
});

it('returns 404 for missing generation identifiers', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('generations.show', ['generation' => (string) Str::uuid()]))
        ->assertNotFound();
});

it('returns to the originating detail page or dashboard after a retry', function () {
    Queue::fake();
    $user = User::factory()->create();
    $generation = Generation::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->from(route('generations.show', $generation))
        ->post(route('generations.retry', $generation))
        ->assertRedirect(route('generations.show', $generation));
    Queue::assertPushed(SubmitGeneration::class);

    $second = Generation::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('generations.retry', $second))
        ->assertRedirect(route('dashboard'));
});
