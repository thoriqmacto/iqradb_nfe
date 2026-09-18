<?php

namespace App\Http\Controllers\Api\V1\Scraper;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Scraper\ParseCodegenRequest;
use App\Services\Scraper\CodegenParser;
use Illuminate\Http\JsonResponse;

/**
 * Converts pasted Playwright Codegen text into structured recipe actions.
 *
 * The pasted source is never executed — see CodegenParser for the full
 * reasoning. This endpoint is pure text-in, structure-out, and touches neither
 * the database nor the browser.
 */
class CodegenController extends Controller
{
    public function parse(ParseCodegenRequest $request, CodegenParser $parser): JsonResponse
    {
        $result = $parser->parse($request->string('source')->toString());

        return response()->json([
            'actions' => $result['actions'],
            'unsupported' => $result['unsupported'],
            'summary' => [
                'converted' => count($result['actions']),
                'unsupported' => count($result['unsupported']),
            ],
        ]);
    }
}
