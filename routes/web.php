<?php

use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->middleware(VerifyPixelforgeSignature::class)->name('webhooks.pixelforge');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])->middleware('throttle:120,1')->name('generations.estimate');
    Route::post('generations', [GenerationController::class, 'store'])->middleware('throttle:20,1')->name('generations.store');
    Route::post('generations/{generation}/retry', [GenerationController::class, 'retry'])->middleware('throttle:6,1')->name('generations.retry');
    Route::post('credits', [CreditController::class, 'store'])->middleware('throttle:20,1')->name('credits.store');
});

require __DIR__.'/settings.php';
