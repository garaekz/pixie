<?php

namespace App\Console\Commands;

use App\Actions\RetryGeneration;
use App\Models\Generation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('pixelforge:retry {generation}')]
#[Description('Retry an uncertain Pixelforge submission')]
class RetrySubmission extends Command
{
    public function handle(RetryGeneration $retry): int
    {
        $generation = Generation::query()->find($this->argument('generation'));
        if ($generation === null) {
            $this->error('Generation not found.');

            return self::FAILURE;
        }

        try {
            $retry->handle($generation);
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Generation queued for retry.');

        return self::SUCCESS;
    }
}
