<?php

namespace App\Actions;

use App\Models\Generation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;

class PixelforgeClient
{
    public static function keyHash(): string
    {
        return hash('sha256', (string) config('services.pixelforge.api_key'));
    }

    public function submit(Generation $generation): Response
    {
        return $this->request($generation->api_key_hash)->withHeaders(['Idempotency-Key' => $generation->id])->post('/generations', [
            'prompt' => $generation->prompt, 'size' => $generation->size->value, 'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'metadata' => ['local_id' => $generation->id],
        ]);
    }

    /** @return array<string, mixed> */
    public function retrieve(Generation $generation): array
    {
        return $this->request($generation->api_key_hash)->get('/generations/'.rawurlencode($generation->provider_id))->throw()->json();
    }

    /** @return array<string, mixed> */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->request()->get('/usage', ['start_date' => $startDate, 'end_date' => $endDate])->throw()->json();
    }

    private function request(?string $keyHash = null): PendingRequest
    {
        $apiKey = PixelforgeCredentials::apiKey($keyHash ?? self::keyHash());
        if ($apiKey === null) {
            throw new LogicException('The original Pixelforge API key is not configured.');
        }

        return Http::baseUrl(rtrim((string) config('services.pixelforge.base_url'), '/'))
            ->withToken($apiKey)
            ->acceptJson()->asJson()->connectTimeout(3)->timeout(15);
    }
}
