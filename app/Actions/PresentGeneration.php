<?php

namespace App\Actions;

use App\Models\Generation;

class PresentGeneration
{
    /** @return array<string, mixed> */
    public function handle(Generation $generation, bool $details = false): array
    {
        $data = [
            'id' => $generation->id,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'status' => $generation->status->value,
            'submission_state' => $generation->submission_state->value,
            'image_url' => $generation->image_url,
            'image_url_expires_at' => $generation->image_url_expires_at?->toIso8601String(),
            'estimate' => Money::decimal($generation->estimate_pico),
            'user_charge' => $generation->user_charge_pico === null ? null : Money::decimal($generation->user_charge_pico),
            'error' => $generation->error,
            'created_at' => $generation->created_at->toIso8601String(),
        ];

        if ($details) {
            $data += [
                'reserved' => Money::decimal($generation->reserved_pico),
                'started_at' => $generation->started_at?->toIso8601String(),
                'completed_at' => $generation->completed_at?->toIso8601String(),
                'usage' => $generation->usage,
            ];
        }

        return $data;
    }
}
