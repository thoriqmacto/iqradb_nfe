<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Scraper\CodegenController;
use App\Http\Controllers\Api\V1\Scraper\RecipeController;
use App\Http\Controllers\Api\V1\Scraper\RunController;
use App\Http\Controllers\Api\V1\Scraper\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json([
    'ok' => true,
    'name' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);

        // Email verification — link target. Signed URL, no auth.
        Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
            ->middleware('signed')
            ->name('verification.verify');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [AuthController::class, 'updateMe']);
        Route::patch('/me/password', [AuthController::class, 'updatePassword']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::post('/email/verification-notification', [AuthController::class, 'sendVerificationEmail'])
            ->middleware('throttle:auth');

        /*
        | SCDB scraper. The URL segment stays "scrapper" to match the menu
        | label the app ships with; everything behind it uses the standard
        | English spelling.
        |
        | Every route here is user-scoped — see the policies and the explicit
        | user_id constraints in the controllers. A run is queued, never
        | executed inline: POST .../runs answers 202 with a run id.
        */
        Route::prefix('scrapper')->name('scrapper.')->group(function () {
            Route::get('/session', [SessionController::class, 'show'])->name('session.show');
            Route::post('/session', [SessionController::class, 'store'])->name('session.store');
            Route::post('/session/validate', [SessionController::class, 'validateSession'])->name('session.validate');
            Route::delete('/session', [SessionController::class, 'destroy'])->name('session.destroy');

            Route::apiResource('recipes', RecipeController::class)
                ->parameters(['recipes' => 'recipe'])
                ->names('recipes');

            Route::post('/recipes/{recipe}/runs', [RunController::class, 'store'])->name('recipes.runs.store');

            Route::get('/runs', [RunController::class, 'index'])->name('runs.index');
            Route::get('/runs/{run}', [RunController::class, 'show'])->name('runs.show');
            Route::get('/runs/{run}/preview', [RunController::class, 'preview'])->name('runs.preview');

            Route::post('/codegen/parse', [CodegenController::class, 'parse'])->name('codegen.parse');
        });
    });
});
