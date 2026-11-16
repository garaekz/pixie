<?php

namespace App\Actions;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;

class ListGenerations
{
    public function __construct(private PresentGeneration $present) {}

    /** @return array<string, mixed> */
    public function handle(User $user, ?GenerationStatus $status = null, bool $recent = false): array
    {
        $user->refresh();
        $query = $user->generations()->latest()->orderByDesc('id');
        $query->when($status, fn ($query) => $query->where('status', $status->value));
        $present = fn (Generation $generation): array => $this->present->handle($generation);

        return [
            'generations' => $recent
                ? ['data' => $query->limit(6)->get()->map($present)]
                : $query->paginate(12)->withQueryString()->through($present),
            'filters' => ['status' => $status?->value],
            'statuses' => array_map(fn (GenerationStatus $item): array => ['value' => $item->value, 'label' => $item->name], GenerationStatus::cases()),
            'wallet' => [
                'balance' => Money::decimal($user->balance_pico),
                'reserved' => Money::decimal($user->reserved_pico),
                'available' => Money::decimal($user->balance_pico - $user->reserved_pico),
            ],
            'providerConfigured' => (bool) config('services.pixelforge.api_key'),
            'options' => [
                'sizes' => array_map(fn (GenerationSize $size): array => ['value' => $size->value, 'label' => $size->name.' · '.str_replace('x', ' × ', $size->value)], GenerationSize::cases()),
                'qualities' => array_map(fn (GenerationQuality $quality): array => ['value' => $quality->value, 'label' => $quality->name], GenerationQuality::cases()),
                'defaults' => ['size' => GenerationSize::Square->value, 'quality' => GenerationQuality::Medium->value],
            ],
        ];
    }
}
