<?php

namespace Database\Factories;

use App\Enums\ScraperRunMode;
use App\Enums\ScraperRunStatus;
use App\Models\ScraperRecipe;
use App\Models\ScraperRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScraperRun> */
class ScraperRunFactory extends Factory
{
    protected $model = ScraperRun::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'scraper_recipe_id' => ScraperRecipe::factory(),
            'mode' => ScraperRunMode::Import,
            'status' => ScraperRunStatus::Queued,
        ];
    }
}
