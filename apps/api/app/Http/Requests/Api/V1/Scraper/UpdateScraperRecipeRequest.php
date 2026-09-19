<?php

namespace App\Http\Requests\Api\V1\Scraper;

use App\Services\Scraper\RecipeValidator;
use App\Services\Scraper\ScdbUrlGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateScraperRecipeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'start_url' => ['sometimes', 'string', 'max:2048'],
            'dataset_key' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'enabled' => ['sometimes', 'boolean'],
            'expected_file_type' => ['sometimes', 'string', 'in:csv,xlsx'],
            'expected_filename_pattern' => ['nullable', 'string', 'max:255'],
            'actions' => ['sometimes', 'array', 'min:1'],
            'import_config' => ['nullable', 'array'],
            'import_config.mapping_version' => ['sometimes', 'integer', 'min:1'],
            'import_config.column_map' => ['sometimes', 'array'],
            'import_config.unique_key_columns' => ['sometimes', 'array'],
            'import_config.unique_key_columns.*' => ['string', 'max:120'],
            'codegen_source' => ['nullable', 'string', 'max:60000'],
        ];
    }

    public function messages(): array
    {
        return [
            'dataset_key.regex' => 'The dataset key must be lowercase letters, digits and underscores, starting with a letter.',
        ];
    }

    public function after(): array
    {
        return [
            // The start URL is the first thing Playwright visits, so it is
            // checked against the SCDB allowlist before the recipe can exist.
            function (Validator $validator): void {
                if (! $this->has('start_url')) {
                    return;
                }

                $reason = app(ScdbUrlGuard::class)->reject((string) $this->input('start_url', ''));

                if ($reason !== null) {
                    $validator->errors()->add('start_url', $reason);
                }
            },
            // Actions are validated against the closed schema. Anything outside
            // the supported vocabulary is rejected here, not at run time.
            function (Validator $validator): void {
                if (! $this->has('actions') || $validator->errors()->has('actions')) {
                    return;
                }

                try {
                    app(RecipeValidator::class)->validate((array) $this->input('actions', []));
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($field, $message);
                        }
                    }
                }
            },
        ];
    }
}
