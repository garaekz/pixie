<?php

namespace App\Actions;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Enums\SubmissionState;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Rules\PixelforgeGenerationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LogicException;

class GenerationLifecycle
{
    public function __construct(private PixelforgePricing $pricing) {}

    /** @param array<string, mixed> $provider */
    public function apply(Generation $generation, array $provider): Generation
    {
        Validator::make($provider, PixelforgeGenerationRules::rules())->validate();

        return DB::transaction(function () use ($generation, $provider): Generation {
            $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);
            $record = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($record->provider_id !== null && $record->provider_id !== $provider['id']) {
                throw new LogicException('Provider generation identity changed.');
            }

            if ($record->isTerminal() || ($record->status === GenerationStatus::Processing && $provider['status'] === GenerationStatus::Queued->value)) {
                return $record;
            }

            $record->provider_id = $provider['id'];
            $record->status = GenerationStatus::from($provider['status']);
            $record->submission_state = SubmissionState::Accepted;
            $record->provider_created_at = $provider['created_at'];
            $record->started_at = $provider['started_at'] ?? null;
            $record->completed_at = $provider['completed_at'] ?? null;
            $record->rates = $provider['rates'] ?? null;
            $record->error = $provider['error']['message'] ?? null;
            $record->next_attempt_at = now()->addMinute();

            if ($record->isTerminal()) {
                if ($user->reserved_pico < $record->reserved_pico) {
                    throw new LogicException('Wallet reservation is smaller than the generation reservation.');
                }

                $cost = $record->status === GenerationStatus::Succeeded
                    ? $this->pricing->providerCost($provider['usage'] ?? [], $provider['rates'] ?? [])
                    : 0;
                $charge = Money::charge($cost);
                $user->reserved_pico -= $record->reserved_pico;
                /** A provider price increase after reservation must never hide the actual charge. */
                $user->balance_pico -= $charge;
                $user->save();

                $record->reserved_pico = 0;
                $record->provider_cost_pico = $cost;
                $record->user_charge_pico = $charge;
                $record->usage = $record->status === GenerationStatus::Succeeded ? $provider['usage'] : null;
                $record->image_url = $record->status === GenerationStatus::Succeeded ? ($provider['image_url'] ?? null) : null;
                $record->image_url_expires_at = $provider['image_url_expires_at'] ?? null;
                $record->next_attempt_at = null;

                if ($record->status === GenerationStatus::Succeeded) {
                    CreditTransaction::query()->create([
                        'user_id' => $user->id, 'generation_id' => $record->id,
                        'type' => CreditTransactionType::Charge, 'amount_pico' => -$charge,
                    ]);
                }
            }

            $record->save();

            return $record;
        }, 3);
    }

    public function reject(Generation $generation, string $message): void
    {
        DB::transaction(function () use ($generation, $message): void {
            $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);
            $record = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($record->isTerminal() || $record->provider_id !== null) {
                return;
            }

            if ($user->reserved_pico < $record->reserved_pico) {
                throw new LogicException('Cannot release an inconsistent wallet reservation.');
            }

            $user->reserved_pico -= $record->reserved_pico;
            $user->save();
            $record->forceFill([
                'status' => GenerationStatus::Failed, 'submission_state' => SubmissionState::Rejected, 'error' => $message,
                'reserved_pico' => 0, 'provider_cost_pico' => 0, 'user_charge_pico' => 0,
                'next_attempt_at' => null, 'completed_at' => now(),
            ])->save();
        }, 3);
    }
}
