<?php

use App\Actions\Money;

it('stores exact decimal dollars as picodollars', function () {
    expect(Money::units('0.373875000'))->toBe(373875000000);
    expect(Money::decimal(373875000000))->toBe('0.373875000000');
});

it('applies the twenty five percent markup without floating point arithmetic', function () {
    expect(Money::charge(Money::units('0.373875000')))->toBe(Money::units('0.467343750'));
});

it('rejects malformed or negative money', function () {
    expect(fn () => Money::units('nope'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Money::units('-1'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Money::charge(-1))->toThrow(InvalidArgumentException::class);
});
