<?php

namespace App\Policies;

use App\Models\ScraperRun;
use App\Models\User;

class ScraperRunPolicy
{
    public function view(User $user, ScraperRun $run): bool
    {
        return $run->user_id === $user->id;
    }
}
