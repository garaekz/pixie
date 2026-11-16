<?php

namespace Database\Seeders;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Seeder;

class CreditTransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::factory()->create(['balance_pico' => 5_000_000_000_000]);
        CreditTransaction::factory()->create(['user_id' => $user->id]);
    }
}
