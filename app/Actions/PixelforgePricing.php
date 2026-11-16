<?php

namespace App\Actions;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class PixelforgePricing
{
    /** @return array<string, mixed> */
    public function catalog(): array
    {
        $baseUrl = rtrim((string) config('services.pixelforge.base_url'), '/');

        return Cache::remember('pixelforge.pricing.'.md5($baseUrl), now()->addMinute(), function () use ($baseUrl): array {
            $catalog = Http::connectTimeout(3)
                ->timeout(10)
                ->get($baseUrl.'/pricing')
                ->throw()
                ->json();

            if (! is_array($catalog)) {
                throw new \InvalidArgumentException('Pixelforge returned an invalid pricing catalog.');
            }

            $this->validateCatalog($catalog);

            return $catalog;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $catalog
     * @return array{estimate: int, maximum: int, rates: array<string, mixed>, catalog: array<string, mixed>}
     */
    public function quote(array $input, ?array $catalog = null): array
    {
        $catalog ??= $this->catalog();
        $this->validateCatalog($catalog);
        $prompt = (string) ($input['prompt'] ?? '');
        $size = $input['size'] ?? GenerationSize::Square;
        $size = $size instanceof GenerationSize ? $size->value : (string) $size;
        $quality = $input['quality'] ?? GenerationQuality::Medium;
        $quality = $quality instanceof GenerationQuality ? $quality->value : (string) $quality;
        $referenceCount = count($input['reference_images'] ?? []);
        $tokens = $catalog['tokens'];
        $rates = $catalog['rates'];
        $textTokens = (int) ceil(Str::length($prompt) / (int) $tokens['characters_per_text_token']);
        $textMaximum = BigDecimal::of((string) $textTokens)
            ->multipliedBy(BigDecimal::one()->plus((string) $tokens['text_token_variance']))
            ->toScale(0, RoundingMode::Ceiling)
            ->toBigInteger()
            ->toInt();
        $imageTokens = $referenceCount * (int) $tokens['per_reference_image'];
        $outputTokens = (int) $tokens['output'][$quality][$size];
        $reasoning = $tokens['reasoning'][$quality];
        $midpointUsage = [
            'input_text_tokens' => $textTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => (int) round(($reasoning['min'] + $reasoning['max']) / 2),
        ];
        $maximumUsage = [
            'input_text_tokens' => $textMaximum,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => (int) $reasoning['max'],
        ];

        return [
            'estimate' => Money::charge($this->providerCost($midpointUsage, $rates)),
            'maximum' => Money::charge($this->providerCost($maximumUsage, $rates)),
            'rates' => $rates,
            'catalog' => $catalog,
        ];
    }

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $rates
     */
    public function providerCost(array $usage, array $rates): int
    {
        $total = BigDecimal::zero();
        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $type) {
            if (! array_key_exists($type, $usage) || ! is_int($usage[$type]) || $usage[$type] < 0) {
                throw new \InvalidArgumentException("Invalid {$type} usage.");
            }

            if (! array_key_exists($type, $rates) || (! is_string($rates[$type]) && ! is_int($rates[$type])) || BigDecimal::of((string) $rates[$type])->isNegative()) {
                throw new \InvalidArgumentException("Invalid {$type} rate.");
            }

            $total = $total->plus(
                BigDecimal::of((string) ($usage[$type] ?? 0))
                    ->multipliedBy((string) $rates[$type])
                    ->dividedBy(1000000, 30, RoundingMode::HalfUp),
            );
        }

        return Money::units($total->__toString());
    }

    /** @param array<string, mixed> $catalog */
    private function validateCatalog(array $catalog): void
    {
        if (! is_array($catalog['rates'] ?? null)) {
            throw new \InvalidArgumentException('Invalid Pixelforge pricing rates.');
        }

        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $type) {
            if (! isset($catalog['rates'][$type]) || (! is_string($catalog['rates'][$type]) && ! is_int($catalog['rates'][$type]))) {
                throw new \InvalidArgumentException('Invalid Pixelforge pricing rates.');
            }

            try {
                if (BigDecimal::of((string) $catalog['rates'][$type])->isNegative()) {
                    throw new \InvalidArgumentException('Invalid Pixelforge pricing rates.');
                }
            } catch (\Throwable $exception) {
                throw new \InvalidArgumentException('Invalid Pixelforge pricing rates.', previous: $exception);
            }
        }

        $tokens = $catalog['tokens'] ?? null;
        if (! is_array($tokens)
            || ! isset($tokens['characters_per_text_token'], $tokens['text_token_variance'], $tokens['per_reference_image'], $tokens['output'], $tokens['reasoning'])
            || ! is_int($tokens['characters_per_text_token'])
            || $tokens['characters_per_text_token'] < 1
            || ! is_numeric($tokens['text_token_variance'])
            || BigDecimal::of((string) $tokens['text_token_variance'])->isNegative()
            || ! is_int($tokens['per_reference_image'])
            || $tokens['per_reference_image'] < 1
            || ! is_array($tokens['output'])
            || ! is_array($tokens['reasoning'])) {
            throw new \InvalidArgumentException('Invalid Pixelforge token pricing.');
        }

        try {
            $variance = BigDecimal::of((string) $tokens['text_token_variance']);
            if ($variance->isNegative() || $variance->isGreaterThan('1')) {
                throw new \InvalidArgumentException('Invalid Pixelforge token pricing.');
            }
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('Invalid Pixelforge token pricing.', previous: $exception);
        }

        foreach (GenerationQuality::cases() as $qualityCase) {
            $quality = $qualityCase->value;
            if (! is_array($tokens['output'][$quality] ?? null) || ! is_array($tokens['reasoning'][$quality] ?? null)) {
                throw new \InvalidArgumentException('Invalid Pixelforge token pricing.');
            }

            foreach (GenerationSize::cases() as $sizeCase) {
                $size = $sizeCase->value;
                if (! isset($tokens['output'][$quality][$size]) || ! is_int($tokens['output'][$quality][$size]) || $tokens['output'][$quality][$size] < 1) {
                    throw new \InvalidArgumentException('Invalid Pixelforge token pricing.');
                }
            }

            $reasoning = $tokens['reasoning'][$quality];
            if (! isset($reasoning['min'], $reasoning['max'])
                || ! is_int($reasoning['min'])
                || ! is_int($reasoning['max'])
                || $reasoning['min'] < 0
                || $reasoning['max'] < $reasoning['min']) {
                throw new \InvalidArgumentException('Invalid Pixelforge token pricing.');
            }
        }
    }
}
