<?php

use App\Models\User;

it('flashes a one-time toast after a credit purchase', function () {
    $user = User::factory()->create();
    $requestId = (string) str()->uuid();

    $this->actingAs($user)
        ->followingRedirects()
        ->post(route('credits.store'), ['request_id' => $requestId])
        ->assertInertia(fn ($page) => $page
            ->hasFlash('toast.type', 'success')
            ->hasFlash('toast.message', '$5 in test credits added. No payment was taken.'));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->missingFlash('toast'));
});
