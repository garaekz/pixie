<?php

namespace App\Actions;

use App\Enums\SubmissionState;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetryGeneration
{
    public function handle(Generation $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $record = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            if ($record->isTerminal()) {
                throw ValidationException::withMessages(['prompt' => 'This generation is already complete.']);
            }
            if (PixelforgeCredentials::apiKey($record->api_key_hash) === null) {
                throw ValidationException::withMessages(['prompt' => 'Restore the original provider key before retrying this generation.']);
            }
            if ($record->next_attempt_at?->isFuture()) {
                throw ValidationException::withMessages(['prompt' => 'This generation is already scheduled for another attempt. Please wait.']);
            }

            $record->forceFill([
                'submission_attempts' => 0, 'next_attempt_at' => now(), 'error' => null,
                'submission_state' => $record->provider_id === null ? SubmissionState::Pending : SubmissionState::Accepted,
            ])->save();
            SubmitGeneration::dispatch($record->id)->afterCommit();
        }, 3);
    }
}
