<?php

namespace Database\Factories;

use App\Models\ScraperRecipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScraperRecipe> */
class ScraperRecipeFactory extends Factory
{
    protected $model = ScraperRecipe::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Loop Index report',
            'description' => 'Loop index export for the current train.',
            'start_url' => 'https://chiyodanfe.ceccms.com/Reports.aspx',
            'dataset_key' => 'loop_index',
            'enabled' => true,
            'expected_file_type' => 'csv',
            'schema_version' => 1,
            'actions' => [
                ['type' => 'click', 'locator' => ['strategy' => 'role', 'role' => 'link', 'name' => 'Reports']],
                ['type' => 'download', 'locator' => ['strategy' => 'role', 'role' => 'button', 'name' => 'Export']],
            ],
        ];
    }
}
