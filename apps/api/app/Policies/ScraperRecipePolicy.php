<?php

namespace App\Policies;

use App\Models\ScraperRecipe;
use App\Models\User;

/**
 * Recipes are strictly per-user. There is no sharing model and no admin
 * override — a recipe carries the URLs and steps for someone's SCDB account.
 */
class ScraperRecipePolicy
{
    public function view(User $user, ScraperRecipe $recipe): bool
    {
        return $recipe->user_id === $user->id;
    }

    public function update(User $user, ScraperRecipe $recipe): bool
    {
        return $recipe->user_id === $user->id;
    }

    public function delete(User $user, ScraperRecipe $recipe): bool
    {
        return $recipe->user_id === $user->id;
    }

    public function run(User $user, ScraperRecipe $recipe): bool
    {
        return $recipe->user_id === $user->id;
    }
}
