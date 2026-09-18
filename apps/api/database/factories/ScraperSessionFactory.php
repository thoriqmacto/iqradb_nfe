<?php

namespace Database\Factories;

use App\Models\ScraperSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScraperSession> */
class ScraperSessionFactory extends Factory
{
    protected $model = ScraperSession::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'host' => 'chiyodanfe.ceccms.com',
            // Shaped like a real Playwright storageState, with no real cookie.
            'storage_state' => ['cookies' => [], 'origins' => []],
            'status' => ScraperSession::STATUS_UNKNOWN,
        ];
    }
}
