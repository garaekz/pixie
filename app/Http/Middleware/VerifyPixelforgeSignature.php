<?php

namespace App\Http\Middleware;

use App\Actions\PixelforgeCredentials;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPixelforgeSignature
{
    public const string KEY_HASH_ATTRIBUTE = 'pixelforge_key_hash';

    /** @param Closure(Request): (Response) $next */
    public function handle(Request $request, Closure $next): Response
    {
        $credentials = array_filter(PixelforgeCredentials::all(), fn (array $credential): bool => $credential['webhook_secrets'] !== []);
        abort_if($credentials === [], 503, 'Webhook signing is not configured.');

        $signature = (string) $request->header('Pixelforge-Signature');
        abort_unless(preg_match('/\At=([0-9]{1,12}),v1=([a-f0-9]{64})\z/', $signature, $parts) === 1, 400, 'Invalid signature.');

        $timestamp = (int) $parts[1];
        abort_if(abs(now()->getTimestamp() - $timestamp) > 300, 400, 'Expired signature.');
        $matchedHashes = [];
        foreach ($credentials as $hash => $credential) {
            foreach ($credential['webhook_secrets'] as $secret) {
                if (hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $parts[2])) {
                    $matchedHashes[] = $hash;
                    break;
                }
            }
        }

        abort_if(count($matchedHashes) > 1, 503, 'Webhook signing configuration is ambiguous.');
        abort_unless(count($matchedHashes) === 1, 400, 'Invalid signature.');
        $request->attributes->set(self::KEY_HASH_ATTRIBUTE, $matchedHashes[0]);

        return $next($request);
    }
}
