<?php

namespace App\Actions;

use App\Models\Generation;

class ShowGeneration
{
    public function __construct(private PresentGeneration $present) {}

    /** @return array{generation: array<string, mixed>} */
    public function handle(Generation $generation): array
    {
        return ['generation' => $this->present->handle($generation, true)];
    }
}
