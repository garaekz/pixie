<?php

namespace App\Enums;

enum PixelforgeEventType: string
{
    case Ping = 'ping';
    case Processing = 'generation.processing';
    case Succeeded = 'generation.succeeded';
    case Failed = 'generation.failed';

    public function status(): ?GenerationStatus
    {
        return match ($this) {
            self::Ping => null,
            self::Processing => GenerationStatus::Processing,
            self::Succeeded => GenerationStatus::Succeeded,
            self::Failed => GenerationStatus::Failed,
        };
    }
}
