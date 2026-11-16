<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPixelforgeSignature
{
    /** @param Closure(Request): (Response) $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.pixelforge.webhook_secret');
        abort_if($secret === '', 503, 'Webhook signing is not configured.');

        $signature = (string) $request->header('Pixelforge-Signature');
        abort_unless(preg_match('/\At=([0-9]{1,12}),v1=([a-f0-9]{64})\z/', $signature, $parts) === 1, 400, 'Invalid signature.');

        $timestamp = (int) $parts[1];
        abort_if(abs(now()->getTimestamp() - $timestamp) > 300, 400, 'Expired signature.');
        abort_unless(hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $parts[2]), 400, 'Invalid signature.');

        return $next($request);
    }
}
