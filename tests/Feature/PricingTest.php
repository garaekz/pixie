<?php

use App\Actions\Money;
use App\Actions\PixelforgePricing;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function pricingCatalog(): array
{
    return [
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

it('returns exact estimate and maximum decimal strings without reserving credits or submitting a generation', function () {
    config()->set('services.pixelforge.base_url', 'https://pixelforge.test/v1');
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.test/v1/pricing' => Http::response(pricingCatalog())]);
    Queue::fake();
    $user = User::factory()->create(['balance_pico' => 5_000_000_000_000]);
    $walletBefore = $user->refresh()->getAttributes();

    $this->actingAs($user)->postJson(route('generations.estimate'), [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.test/a.png', 'https://example.test/b.png'],
    ])->assertOk()->assertExactJson([
        'estimate' => '0.412562500000',
        'maximum' => '0.467343750000',
    ]);

    expect($user->fresh()->getAttributes())->toBe($walletBefore);
    $this->assertDatabaseCount('generations', 0);
    $this->assertDatabaseCount('credit_transactions', 0);
    Queue::assertNothingPushed();
    Http::assertSentCount(1);
});

it('quotes the midpoint estimate and maximum customer charge', function () {
    $quote = app(PixelforgePricing::class)->quote([
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.test/a.png', 'https://example.test/b.png'],
    ], pricingCatalog());

    expect($quote['maximum'])->toBe(Money::units('0.467343750'))
        ->and($quote['estimate'])->toBe(Money::units('0.412562500'));
});

it('uses provider defaults when only a prompt is supplied', function () {
    $quote = app(PixelforgePricing::class)->quote(['prompt' => 'a default scene'], pricingCatalog());

    expect($quote['estimate'])->toBeInt()
        ->and($quote['maximum'])->toBeInt();
});

it('calculates provider cost from the four billable token buckets', function () {
    expect(app(PixelforgePricing::class)->providerCost([
        'input_text_tokens' => 115,
        'input_image_tokens' => 1530,
        'output_tokens' => 6450,
        'reasoning_tokens' => 8000,
        'total_tokens' => 16095,
    ], pricingCatalog()['rates']))->toBe(Money::units('0.373875000'));
});

it('rejects incomplete, negative, or malformed provider usage', function () {
    $pricing = app(PixelforgePricing::class);
    $rates = pricingCatalog()['rates'];

    expect(fn () => $pricing->providerCost(['input_text_tokens' => 1], $rates))->toThrow(InvalidArgumentException::class);
    expect(fn () => $pricing->providerCost([
        'input_text_tokens' => -1,
        'input_image_tokens' => 0,
        'output_tokens' => 0,
        'reasoning_tokens' => 0,
    ], $rates))->toThrow(InvalidArgumentException::class);
    expect(fn () => $pricing->providerCost([
        'input_text_tokens' => '1',
        'input_image_tokens' => 0,
        'output_tokens' => 0,
        'reasoning_tokens' => 0,
    ], $rates))->toThrow(InvalidArgumentException::class);
});

it('counts unicode prompt characters and rejects invalid catalogs', function () {
    $pricing = app(PixelforgePricing::class);
    $catalog = pricingCatalog();
    $catalog['tokens']['characters_per_text_token'] = 1;
    $catalog['tokens']['text_token_variance'] = '0.15';

    expect($pricing->quote([
        'prompt' => '😀😀',
        'size' => '1536x1024',
        'quality' => 'high',
    ], $catalog)['catalog'])->toBe($catalog);

    unset($catalog['rates']['output_tokens']);
    expect(fn () => $pricing->quote(['prompt' => 'x'], $catalog))->toThrow(InvalidArgumentException::class);
});

it('rejects incomplete or malformed token bounds before selecting a quote', function () {
    $pricing = app(PixelforgePricing::class);

    $missingSize = pricingCatalog();
    unset($missingSize['tokens']['output']['high']['1024x1536']);
    expect(fn () => $pricing->quote(['prompt' => 'x'], $missingSize))->toThrow(InvalidArgumentException::class);

    $negativeReasoning = pricingCatalog();
    $negativeReasoning['tokens']['reasoning']['high']['min'] = -1;
    expect(fn () => $pricing->quote(['prompt' => 'x'], $negativeReasoning))->toThrow(InvalidArgumentException::class);

    $reversedReasoning = pricingCatalog();
    $reversedReasoning['tokens']['reasoning']['high'] = ['min' => 9_000, 'max' => 8_000];
    expect(fn () => $pricing->quote(['prompt' => 'x'], $reversedReasoning))->toThrow(InvalidArgumentException::class);
});

it('loads pricing once and fails clearly when the provider is unavailable', function () {
    config()->set('services.pixelforge.base_url', 'https://pixelforge.test/v1');
    Http::fake(['pixelforge.test/*' => Http::response(pricingCatalog())]);

    $pricing = app(PixelforgePricing::class);
    $pricing->catalog();
    $pricing->catalog();

    Http::assertSentCount(1);
    config()->set('services.pixelforge.base_url', 'https://pixelforge-down.test/v1');
    Http::fake(['pixelforge-down.test/*' => Http::response([], 503)]);

    expect(fn () => $pricing->catalog())->toThrow(RequestException::class);
});

it('rejects a successful but malformed pricing response', function () {
    config()->set('services.pixelforge.base_url', 'https://pixelforge-malformed.test/v1');
    Http::fake(['pixelforge-malformed.test/*' => Http::response('not-json', 200)]);

    expect(fn () => app(PixelforgePricing::class)->catalog())->toThrow(InvalidArgumentException::class);
});
