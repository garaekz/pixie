<?php

namespace App\Jobs;

use App\Actions\GenerationLifecycle;
use App\Actions\PixelforgeClient;
use App\Enums\GenerationStatus;
use App\Enums\SubmissionState;
use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnexpectedValueException;

class SubmitGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 25;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    public function handle(PixelforgeClient $client, GenerationLifecycle $lifecycle): void
    {
        $generation = Generation::query()
            ->whereKey($this->generationId)
            ->where('api_key_hash', PixelforgeClient::keyHash())
            ->first();

        if ($generation === null || $generation->isTerminal()) {
            return;
        }

        if ($generation->provider_id !== null) {
            $this->retrieve($generation, $client, $lifecycle);

            return;
        }

        if (! $this->claimAttempt()) {
            return;
        }

        try {
            $response = $client->submit($generation->fresh());
        } catch (ConnectionException $exception) {
            $this->markAmbiguous('Pixelforge submission timed out or could not connect.');

            return;
        } catch (Throwable $exception) {
            $this->reportUnexpected($exception);
            $this->markAmbiguous('Pixelforge submission failed unexpectedly.');

            return;
        }

        if ($response->successful()) {
            try {
                $provider = $response->json();
                if (! is_array($provider) || ! isset($provider['id'], $provider['status'])) {
                    throw new UnexpectedValueException('Pixelforge returned an invalid generation.');
                }
                $lifecycle->apply($generation->fresh(), $provider);
            } catch (Throwable $exception) {
                $this->reportUnexpected($exception);
                $this->markAmbiguous('Pixelforge returned an invalid generation.');
            }

            return;
        }

        $code = (string) ($response->json('error.code') ?? '');
        $generation = $generation->fresh();
        if (! $generation->submission_uncertain && (in_array($response->status(), [400, 401, 409, 422], true) || $code === 'daily_limit_exceeded')) {
            $lifecycle->reject($generation->fresh(), (string) ($response->json('error.message') ?? 'Pixelforge rejected this generation.'));

            return;
        }

        if (in_array($response->status(), [429, 503], true)) {
            if ($this->attemptsExhausted()) {
                if ($generation->submission_uncertain) {
                    $this->markAmbiguous('Pixelforge did not resolve the ambiguous submission after five attempts.');
                } else {
                    $lifecycle->reject($generation, 'Pixelforge did not accept the generation after five attempts.');
                }

                return;
            }

            $this->retryAfter($response);

            return;
        }

        $this->markAmbiguous('Pixelforge returned an unexpected submission response.');
    }

    private function claimAttempt(): bool
    {
        return DB::transaction(function (): bool {
            $generation = Generation::query()->lockForUpdate()->find($this->generationId);
            if ($generation === null || $generation->isTerminal() || $generation->provider_id !== null || $generation->api_key_hash !== PixelforgeClient::keyHash()) {
                return false;
            }

            if ($generation->next_attempt_at !== null && $generation->next_attempt_at->isFuture()) {
                return false;
            }

            if ($generation->submission_attempts >= 5) {
                $generation->forceFill([
                    'submission_state' => SubmissionState::Uncertain->value, 'submission_uncertain' => true,
                    'next_attempt_at' => null, 'error' => 'Pixelforge submission needs manual recovery.',
                ])->save();

                return false;
            }

            if ($generation->submission_attempts > 0 && $generation->next_attempt_at !== null && $generation->error === null) {
                $generation->submission_uncertain = true;
            }

            $generation->increment('submission_attempts');
            $generation->forceFill(['next_attempt_at' => now()->addMinute(), 'error' => null])->save();

            return true;
        });
    }

    private function attemptsExhausted(): bool
    {
        return (int) Generation::query()->whereKey($this->generationId)->value('submission_attempts') >= 5;
    }

    private function retryAfter(Response $response): void
    {
        $attempts = (int) Generation::query()->whereKey($this->generationId)->value('submission_attempts');
        $retryAfter = max((int) $response->header('Retry-After'), 2 ** $attempts);
        Generation::query()->whereKey($this->generationId)->whereNotIn('status', [GenerationStatus::Succeeded->value, GenerationStatus::Failed->value])->update([
            'next_attempt_at' => now()->addSeconds($retryAfter),
            'error' => 'Pixelforge is temporarily unavailable; retrying.',
        ]);
    }

    private function markAmbiguous(string $message): void
    {
        $generation = Generation::query()->find($this->generationId);
        if ($generation === null || $generation->isTerminal()) {
            return;
        }

        if ($generation->provider_id !== null) {
            Generation::query()->whereKey($this->generationId)->whereNotIn('status', [GenerationStatus::Succeeded->value, GenerationStatus::Failed->value])->update([
                'next_attempt_at' => now()->addMinute(),
                'error' => 'Pixelforge retrieval requires another poll.',
            ]);

            return;
        }

        $attributes = [
            'submission_uncertain' => true,
            'error' => $message,
        ];
        if ($generation->submission_attempts >= 5) {
            $attributes['submission_state'] = SubmissionState::Uncertain->value;
            $attributes['next_attempt_at'] = null;
        } else {
            $attributes['next_attempt_at'] = now()->addMinute();
        }
        Generation::query()->whereKey($this->generationId)->whereNotIn('status', [GenerationStatus::Succeeded->value, GenerationStatus::Failed->value])->update($attributes);
    }

    private function retrieve(Generation $generation, PixelforgeClient $client, GenerationLifecycle $lifecycle): void
    {
        try {
            $provider = $client->retrieve($generation->provider_id);
            $lifecycle->apply($generation, $provider);
        } catch (ConnectionException $exception) {
            $this->retryRetrieval();
        } catch (RequestException $exception) {
            if ($exception->response->status() !== 429 && ! $exception->response->serverError()) {
                $this->reportUnexpected($exception);
            }
            $this->retryRetrieval();
        } catch (Throwable $exception) {
            $this->reportUnexpected($exception);
            $this->retryRetrieval();
        }
    }

    private function reportUnexpected(Throwable $exception): void
    {
        report($exception instanceof ValidationException
            ? new UnexpectedValueException('Pixelforge response failed application validation.', previous: $exception)
            : $exception);
    }

    private function retryRetrieval(): void
    {
        Generation::query()->whereKey($this->generationId)->whereNotIn('status', [GenerationStatus::Succeeded->value, GenerationStatus::Failed->value])->update([
            'next_attempt_at' => now()->addMinute(),
            'error' => 'Pixelforge retrieval failed; retrying.',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->markAmbiguous('Submission worker failed; recovery will retry this generation.');
    }
}
