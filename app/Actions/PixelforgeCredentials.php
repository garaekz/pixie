<?php

namespace App\Actions;

class PixelforgeCredentials
{
    /** @return array<string, array{api_key: string, webhook_secrets: list<string>}> */
    public static function all(): array
    {
        $credentials = [];

        foreach (['', 'previous_'] as $prefix) {
            $apiKey = (string) config('services.pixelforge.'.$prefix.'api_key');
            if ($apiKey === '') {
                continue;
            }

            $hash = hash('sha256', $apiKey);
            $credentials[$hash] ??= ['api_key' => $apiKey, 'webhook_secrets' => []];
            $secret = (string) config('services.pixelforge.'.$prefix.'webhook_secret');
            if ($secret !== '' && ! in_array($secret, $credentials[$hash]['webhook_secrets'], true)) {
                $credentials[$hash]['webhook_secrets'][] = $secret;
            }
        }

        return $credentials;
    }

    /** @return list<string> */
    public static function keyHashes(): array
    {
        return array_keys(self::all());
    }

    public static function apiKey(string $hash): ?string
    {
        return self::all()[$hash]['api_key'] ?? null;
    }
}
