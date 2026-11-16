<?php

namespace Database\Factories;

use App\Enums\PixelforgeEventType;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => 'evt_'.fake()->uuid(), 'type' => PixelforgeEventType::Ping, 'payload' => ['type' => PixelforgeEventType::Ping->value],
        ];
    }
}
