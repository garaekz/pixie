<?php

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class GenerationPolicy
{
    public function view(User $user, Generation $generation): Response
    {
        return $user->id === $generation->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function retry(User $user, Generation $generation): bool
    {
        return $user->id === $generation->user_id;
    }
}
