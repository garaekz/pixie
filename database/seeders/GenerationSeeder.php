<?php

namespace Database\Seeders;

use App\Enums\GenerationStatus;
use App\Enums\SubmissionState;
use App\Models\Generation;
use Illuminate\Database\Seeder;

class GenerationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Generation::factory()->create([
            'api_key_hash' => hash('sha256', 'seeded-fixture'),
            'status' => GenerationStatus::Failed, 'submission_state' => SubmissionState::Rejected,
            'error' => 'Seeded failure example.', 'provider_cost_pico' => 0, 'user_charge_pico' => 0,
        ]);
    }
}
