<?php

namespace App\Http\Controllers;

use App\Actions\ProcessPixelforgeWebhook;
use App\Http\Requests\PixelforgeWebhookRequest;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(PixelforgeWebhookRequest $request, ProcessPixelforgeWebhook $process): Response
    {
        $process->handle($request->validated(), $request->providerKeyHash());

        return response()->noContent();
    }
}
