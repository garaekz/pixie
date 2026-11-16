<?php

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;

it('preserves financial history during account deletion', function (string $history) {
    $user = User::factory()->create();
    if ($history === 'generation') {
        Generation::factory()->create(['user_id' => $user->id]);
    } else {
        CreditTransaction::factory()->create(['user_id' => $user->id]);
    }

    $this->actingAs($user)->from(route('profile.edit'))->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['password' => 'Accounts with generation or credit records cannot be deleted. Contact the operator.']);

    $this->assertModelExists($user);
    $this->assertAuthenticatedAs($user);
})->with(['generation', 'credit']);
