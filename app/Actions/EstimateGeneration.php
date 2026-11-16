<?php

namespace App\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EstimateGeneration
{
    public function __construct(private PixelforgePricing $pricing) {}

    /** @param array<string, mixed> $input
     * @return array{estimate: string, maximum: string}
     */
    public function handle(array $input): array
    {
        try {
            $quote = $this->pricing->quote($input);
        } catch (ConnectionException|RequestException|InvalidArgumentException) {
            throw ValidationException::withMessages(['prompt' => 'Pricing is temporarily unavailable. Please try again shortly.']);
        }

        return ['estimate' => Money::decimal($quote['estimate']), 'maximum' => Money::decimal($quote['maximum'])];
    }
}
