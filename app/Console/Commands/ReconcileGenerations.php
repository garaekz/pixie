<?php

namespace App\Console\Commands;

use App\Actions\PixelforgeCredentials;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('pixelforge:reconcile {--limit=30}')]
#[Description('Retry due Pixelforge generations')]
class ReconcileGenerations extends Command
{
    public function handle(): int
    {
        $limit = min(30, max(1, (int) $this->option('limit')));
        $generations = Generation::query()
            ->whereNotIn('status', [GenerationStatus::Succeeded->value, GenerationStatus::Failed->value])
            ->whereIn('api_key_hash', PixelforgeCredentials::keyHashes())
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', Carbon::now())
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        foreach ($generations as $generation) {
            SubmitGeneration::dispatch($generation->id);
        }

        $this->info("Dispatched {$generations->count()} generation(s).");

        return self::SUCCESS;
    }
}
