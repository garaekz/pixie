<?php

use App\Actions\GenerationLifecycle;
use App\Actions\ReserveGeneration;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('prevents stale wallet snapshots from reserving the same available credits', function () {
    config()->set('services.pixelforge.api_key', 'pf_wallet_test');
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'tokens' => [
            'characters_per_text_token' => 4, 'text_token_variance' => 0.15, 'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => ['low' => ['min' => 0, 'max' => 500], 'medium' => ['min' => 200, 'max' => 2000], 'high' => ['min' => 1000, 'max' => 8000]],
        ],
    ])]);
    $user = User::factory()->create(['balance_pico' => 30_000_000_000]);
    $staleUser = $user->fresh();
    $input = ['request_id' => (string) Str::uuid(), 'prompt' => 'test', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];

    app(ReserveGeneration::class)->handle($user, $input);
    $input['request_id'] = (string) Str::uuid();

    expect(fn () => app(ReserveGeneration::class)->handle($staleUser, $input))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('generations', 1);
    expect($user->fresh()->reserved_pico)->toBe(21_825_000_000);
    Http::assertSentCount(1);
});

it('records the full exact charge when provider prices increase after reservation', function () {
    $user = User::factory()->create(['balance_pico' => 10_000_000_000, 'reserved_pico' => 10_000_000_000]);
    $generation = Generation::factory()->create(['user_id' => $user->id, 'reserved_pico' => 10_000_000_000]);

    app(GenerationLifecycle::class)->apply($generation, [
        'id' => 'gen_repriced', 'status' => 'succeeded',
        'created_at' => now()->subMinute()->toIso8601String(), 'completed_at' => now()->toIso8601String(),
        'usage' => ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 200],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
    ]);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->fresh()->provider_cost_pico)->toBe(46_550_000_000)
        ->and($generation->fresh()->user_charge_pico)->toBe(58_187_500_000)
        ->and($user->fresh()->balance_pico)->toBe(-48_187_500_000)
        ->and($user->fresh()->reserved_pico)->toBe(0);
    $this->assertDatabaseHas('credit_transactions', ['generation_id' => $generation->id, 'amount_pico' => -58_187_500_000]);
});
