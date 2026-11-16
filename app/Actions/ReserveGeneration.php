<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\SubmissionState;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReserveGeneration
{
    public function __construct(private PixelforgePricing $pricing) {}

    /** @param array{request_id: string, prompt: string, size: string, quality: string, reference_images?: list<string>} $input */
    public function handle(User $user, array $input): Generation
    {
        $existing = Generation::query()->find($input['request_id']);
        if ($existing !== null) {
            $this->assertSameRequest($existing, $user, $input);

            return $existing;
        }

        if (! config('services.pixelforge.api_key')) {
            throw ValidationException::withMessages(['prompt' => 'Image generation is not configured yet.']);
        }

        $quote = $this->pricing->quote($input);

        return DB::transaction(function () use ($user, $input, $quote): Generation {
            $wallet = User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = Generation::query()->find($input['request_id']);
            if ($existing !== null) {
                $this->assertSameRequest($existing, $wallet, $input);

                return $existing;
            }

            if ($wallet->balance_pico - $wallet->reserved_pico < $quote['maximum']) {
                throw ValidationException::withMessages(['prompt' => 'Not enough available credits. Buy credits before generating.']);
            }

            $record = Generation::query()->create([
                'id' => $input['request_id'], 'user_id' => $wallet->id,
                'status' => GenerationStatus::Queued, 'submission_state' => SubmissionState::Pending,
                'api_key_hash' => PixelforgeClient::keyHash(),
                'prompt' => $input['prompt'], 'size' => $input['size'], 'quality' => $input['quality'],
                'reference_images' => $input['reference_images'] ?? [],
                'estimate_pico' => $quote['estimate'], 'reserved_pico' => $quote['maximum'],
                'next_attempt_at' => now(),
            ]);
            $wallet->reserved_pico += $quote['maximum'];
            $wallet->save();

            return $record;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    private function assertSameRequest(Generation $generation, User $user, array $input): void
    {
        abort_unless($generation->user_id === $user->id, 404);
        if ($generation->prompt !== $input['prompt']
            || $generation->size->value !== $input['size']
            || $generation->quality->value !== $input['quality']
            || $generation->reference_images !== ($input['reference_images'] ?? [])) {
            throw ValidationException::withMessages(['request_id' => 'This request was already used with different generation settings.']);
        }
    }
}
