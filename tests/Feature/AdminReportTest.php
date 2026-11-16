<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set([
        'services.pixelforge.api_key' => 'pf_test_key',
        'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
    ]);
});

it('returns 403 when non-admins access the report', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get(route('admin.report'))->assertForbidden();
});

it('shows admins spend, cost, and profit grouped by UTC date and user', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/usage*' => Http::response([
            'total' => ['cost_usd' => '0.044250000'],
            'data' => [['date' => '2026-11-16', 'cost_usd' => '0.044250000']],
        ]),
    ]);

    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    Generation::factory()->create([
        'user_id' => $user->id,
        'status' => 'succeeded',
        'provider_cost_pico' => 44_250_000_000,
        'user_charge_pico' => 55_312_500_000,
        'completed_at' => '2026-11-16 13:00:00',
    ]);

    $response = $this->actingAs($admin)->get(route('admin.report', [
        'start_date' => '2026-11-16',
        'end_date' => '2026-11-16',
    ]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/Report')
        ->where('rows.0.date', '2026-11-16')
        ->where('rows.0.user.email', 'ada@example.com')
        ->where('rows.0.provider_cost', '0.044250000000')
        ->where('rows.0.user_spend', '0.055312500000')
        ->where('rows.0.profit', '0.011062500000')
        ->where('totals.generations', 1)
        ->where('reconciliation.provider_cost', '0.044250000'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/usage'));
});

it('scopes admin totals to the configured provider key', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/usage*' => Http::response(['total' => ['cost_usd' => '0.044250000'], 'data' => []]),
    ]);

    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    Generation::factory()->create([
        'user_id' => $user->id,
        'status' => 'succeeded',
        'api_key_hash' => hash('sha256', 'pf_test_key'),
        'provider_cost_pico' => 44_250_000_000,
        'user_charge_pico' => 55_312_500_000,
        'completed_at' => '2026-11-16 13:00:00',
    ]);
    Generation::factory()->create([
        'user_id' => $user->id,
        'status' => 'succeeded',
        'api_key_hash' => hash('sha256', 'another-environment-key'),
        'provider_cost_pico' => 100_000_000_000,
        'user_charge_pico' => 125_000_000_000,
        'completed_at' => '2026-11-16 14:00:00',
    ]);

    $this->actingAs($admin)->get(route('admin.report', ['start_date' => '2026-11-16', 'end_date' => '2026-11-16']))
        ->assertInertia(fn ($page) => $page
            ->where('totals.provider_cost', '0.044250000000')
            ->where('totals.user_spend', '0.055312500000')
            ->where('totals.generations', 1)
            ->where('rows', fn ($rows): bool => $rows->count() === 1));
});

it('assigns provider costs to the UTC completion day across midnight', function () {
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/usage*' => Http::response(['total' => ['cost_usd' => '0.044250000']])]);
    $admin = User::factory()->create(['is_admin' => true]);
    Generation::factory()->create([
        'status' => 'succeeded', 'created_at' => '2026-11-15 23:59:59',
        'completed_at' => '2026-11-16 00:00:01',
        'provider_cost_pico' => 44_250_000_000, 'user_charge_pico' => 55_312_500_000,
    ]);

    $this->actingAs($admin)->get(route('admin.report', ['start_date' => '2026-11-16', 'end_date' => '2026-11-16']))
        ->assertInertia(fn ($page) => $page->where('rows.0.date', '2026-11-16')->where('totals.generations', 1));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'start_date=2026-11-16'));
});
