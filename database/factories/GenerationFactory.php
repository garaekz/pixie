<?php

namespace Database\Factories;

use App\Actions\PixelforgeClient;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Enums\SubmissionState;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->uuid(), 'user_id' => User::factory(),
            'api_key_hash' => PixelforgeClient::keyHash(),
            'prompt' => 'a test image', 'size' => GenerationSize::Square, 'quality' => GenerationQuality::Medium,
            'status' => GenerationStatus::Queued, 'submission_state' => SubmissionState::Pending,
            'reference_images' => [], 'estimate_pico' => 0, 'reserved_pico' => 0,
        ];
    }
}
