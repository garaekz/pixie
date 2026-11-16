<?php

namespace App\Actions;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseCredits
{
    public function handle(User $user, string $requestId): void
    {
        DB::transaction(function () use ($user, $requestId): void {
            $wallet = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($wallet->creditTransactions()->where('request_id', $requestId)->exists()) {
                return;
            }

            $amount = Money::units('5');
            if ($wallet->balance_pico > PHP_INT_MAX - $amount) {
                throw ValidationException::withMessages(['request_id' => 'This wallet has reached its credit limit.']);
            }

            CreditTransaction::query()->create([
                'user_id' => $wallet->id, 'request_id' => $requestId,
                'type' => CreditTransactionType::Purchase, 'amount_pico' => $amount,
            ]);
            $wallet->balance_pico += $amount;
            $wallet->save();
        }, 3);
    }
}
