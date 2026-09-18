<?php

namespace App\Http\Controllers\Api\V1\Scraper;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Scraper\StoreScraperRecipeRequest;
use App\Http\Requests\Api\V1\Scraper\UpdateScraperRecipeRequest;
use App\Http\Resources\ScraperRecipeResource;
use App\Models\ScraperRecipe;
use App\Services\Scraper\RecipeValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecipeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Scoped by user at the query, not filtered afterwards — the only
        // rows that ever load are the caller's.
        $recipes = ScraperRecipe::where('user_id', $request->user()->id)
            ->latest('id')
            ->get();

        return ScraperRecipeResource::collection($recipes);
    }

    public function store(StoreScraperRecipeRequest $request, RecipeValidator $validator): JsonResponse
    {
        $data = $request->validated();

        $recipe = new ScraperRecipe([
            ...$data,
            'actions' => $validator->validate($data['actions']),
            'schema_version' => RecipeValidator::SCHEMA_VERSION,
        ]);

        $recipe->user_id = $request->user()->id;
        $recipe->save();

        return (new ScraperRecipeResource($recipe))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, ScraperRecipe $recipe): ScraperRecipeResource
    {
        $this->authorize('view', $recipe);

        return new ScraperRecipeResource($recipe);
    }

    public function update(
        UpdateScraperRecipeRequest $request,
        ScraperRecipe $recipe,
        RecipeValidator $validator,
    ): ScraperRecipeResource {
        $this->authorize('update', $recipe);

        $data = $request->validated();

        if (array_key_exists('actions', $data)) {
            $data['actions'] = $validator->validate($data['actions']);
            $data['schema_version'] = RecipeValidator::SCHEMA_VERSION;
        }

        $recipe->fill($data)->save();

        return new ScraperRecipeResource($recipe->fresh());
    }

    public function destroy(Request $request, ScraperRecipe $recipe): JsonResponse
    {
        $this->authorize('delete', $recipe);

        $recipe->delete();

        return response()->json([], 204);
    }
}
