<?php

namespace App\Actions;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class Money
{
    public const int SCALE = 12;

    public static function units(string $amount): int
    {
        try {
            $decimal = BigDecimal::of($amount);
            if ($decimal->isNegative()) {
                throw new InvalidArgumentException('Money cannot be negative.');
            }

            return $decimal
                ->multipliedBy('1000000000000')
                ->toScale(0, RoundingMode::HalfUp)
                ->toBigInteger()
                ->toInt();
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Invalid money amount.', previous: $exception);
        }
    }

    public static function decimal(int $units): string
    {
        return BigDecimal::ofUnscaledValue($units, self::SCALE)->__toString();
    }

    public static function charge(int $providerCost): int
    {
        if ($providerCost < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }

        return BigDecimal::ofUnscaledValue($providerCost, self::SCALE)
            ->multipliedBy('1.25')
            ->multipliedBy('1000000000000')
            ->toScale(0, RoundingMode::HalfUp)
            ->toBigInteger()
            ->toInt();
    }
}
