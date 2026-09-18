<?php

namespace App\Http\Resources;

use App\Models\ScraperRecipe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ScraperRecipe */
class ScraperRecipeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'start_url' => $this->start_url,
            'dataset_key' => $this->dataset_key,
            'enabled' => $this->enabled,
            'expected_file_type' => $this->expected_file_type,
            'expected_filename_pattern' => $this->expected_filename_pattern,
            'schema_version' => $this->schema_version,
            'actions' => $this->actions,
            'import_config' => $this->import_config,
            'has_codegen_source' => $this->codegen_source !== null,
            'last_success_run_at' => $this->last_success_run_at?->toIso8601String(),
            'last_failed_run_at' => $this->last_failed_run_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
