<?php

namespace App\Actions;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateGeneration
{
    public function __construct(private ReserveGeneration $reserve) {}

    /** @param array{request_id: string, prompt: string, size: string, quality: string, reference_images?: list<string>} $input */
    public function handle(User $user, array $input): Generation
    {
        try {
            $generation = $this->reserve->handle($user, $input);
        } catch (ConnectionException|RequestException|InvalidArgumentException) {
            throw ValidationException::withMessages(['prompt' => 'Pricing is temporarily unavailable. No credits were reserved. Please try again.']);
        }

        if ($generation->wasRecentlyCreated) {
            SubmitGeneration::dispatch($generation->id)->afterCommit();
        }

        return $generation;
    }
}
