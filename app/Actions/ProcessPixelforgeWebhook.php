<?php

namespace App\Actions;

use App\Enums\PixelforgeEventType;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ProcessPixelforgeWebhook
{
    public function __construct(private GenerationLifecycle $lifecycle) {}

    /** @param array<string, mixed> $event */
    public function handle(array $event, string $apiKeyHash): void
    {
        DB::transaction(function () use ($event, $apiKeyHash): void {
            $type = PixelforgeEventType::from($event['type']);
            $generation = null;
            /** @var array<string, mixed> $provider */
            $provider = $event['data']['object'] ?? [];
            if ($type !== PixelforgeEventType::Ping) {
                $generation = Generation::query()->where('provider_id', $provider['id'])->first();
                if ($generation === null) {
                    $generation = Generation::query()->whereKey($provider['metadata']['local_id'] ?? null)->first();
                }

                if ($generation === null) {
                    throw (new ModelNotFoundException)->setModel(Generation::class);
                }

                if ($generation->api_key_hash !== $apiKeyHash) {
                    throw new HttpException(409, 'Generation belongs to a different API key.');
                }

                if ($type->status()?->value !== $provider['status']) {
                    throw new HttpException(422, 'Event and generation status differ.');
                }
            }

            $inserted = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $event['id'], 'type' => $type->value,
                'payload' => json_encode($event, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($inserted === 0 || $generation === null) {
                return;
            }

            $this->lifecycle->apply($generation, $provider);
        }, 3);
    }
}
