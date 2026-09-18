<?php

namespace App\Http\Requests\Api\V1\Scraper;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Accepts a Playwright storageState JSON blob.
 *
 * The payload is validated for *shape* only — we never log it, echo it back, or
 * include it in a validation message, because it contains SCDB session cookies.
 */
class StoreScraperSessionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'storage_state' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'storage_state.required' => 'Upload the scdb-auth.json file produced by Playwright Codegen.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $raw = (string) $this->input('storage_state', '');

                $max = (int) config('scraper.max_storage_state_bytes', 1048576);

                if (strlen($raw) > $max) {
                    $validator->errors()->add('storage_state', 'That authentication file is too large to be a Playwright storage state.');

                    return;
                }

                $decoded = json_decode($raw, true);

                if (! is_array($decoded)) {
                    // Deliberately generic: never quote the input back.
                    $validator->errors()->add('storage_state', 'That file is not valid JSON.');

                    return;
                }

                if (! array_key_exists('cookies', $decoded) && ! array_key_exists('origins', $decoded)) {
                    $validator->errors()->add(
                        'storage_state',
                        'That does not look like a Playwright storage state (no "cookies" or "origins" key).'
                    );

                    return;
                }

                if (isset($decoded['cookies']) && ! is_array($decoded['cookies'])) {
                    $validator->errors()->add('storage_state', 'The "cookies" entry must be a list.');
                }
            },
        ];
    }

    /** @return array<string, mixed> */
    public function storageState(): array
    {
        return (array) json_decode((string) $this->input('storage_state'), true);
    }
}
