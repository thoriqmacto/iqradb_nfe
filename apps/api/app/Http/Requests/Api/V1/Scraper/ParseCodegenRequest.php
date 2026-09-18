<?php

namespace App\Http\Requests\Api\V1\Scraper;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pasted Playwright Codegen text.
 *
 * This is data, not code. It is parsed by string matching and never executed.
 */
class ParseCodegenRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'max:60000'],
        ];
    }
}
