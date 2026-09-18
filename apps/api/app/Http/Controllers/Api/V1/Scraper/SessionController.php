<?php

namespace App\Http\Controllers\Api\V1\Scraper;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Scraper\StoreScraperSessionRequest;
use App\Models\ScraperSession;
use App\Services\Scraper\SessionValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The stored SCDB browser session.
 *
 * Note what this controller never does: return `storage_state`. There is no
 * endpoint, field or debug flag that reads it back out. Once uploaded, the only
 * consumer is the Node worker, over stdin, at run time.
 */
class SessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $session = $this->find($request);

        return response()->json(['session' => $this->present($session)]);
    }

    public function store(StoreScraperSessionRequest $request): JsonResponse
    {
        // `user_id` is deliberately not fillable — the owner is associated
        // explicitly, the same way the repo's other user-scoped resources do it.
        $session = $this->find($request) ?? new ScraperSession;

        $session->fill([
            'host' => $this->host(),
            'storage_state' => $request->storageState(),
            'status' => ScraperSession::STATUS_UNKNOWN,
            'last_validated_at' => null,
            'last_validation_error' => null,
        ]);

        $session->user()->associate($request->user());
        $session->save();

        return response()->json([
            'session' => $this->present($session),
            'message' => 'Authentication state stored. Validate it to confirm SCDB still accepts it.',
        ], 201);
    }

    public function validateSession(Request $request, SessionValidator $validator): JsonResponse
    {
        $session = $this->find($request);

        if ($session === null) {
            return response()->json([
                'message' => 'No SCDB authentication state is configured.',
            ], 404);
        }

        $result = $validator->validate($session);

        return response()->json([
            'session' => $this->present($session->fresh()),
            'result' => $result['status'],
            'message' => $result['message'],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->find($request)?->delete();

        return response()->json(['session' => null]);
    }

    private function find(Request $request): ?ScraperSession
    {
        return ScraperSession::where('user_id', $request->user()->id)
            ->where('host', $this->host())
            ->first();
    }

    private function host(): string
    {
        return (string) parse_url((string) config('scraper.base_url'), PHP_URL_HOST);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function present(?ScraperSession $session): ?array
    {
        if ($session === null) {
            return null;
        }

        return [
            'host' => $session->host,
            'status' => $session->status,
            'last_validated_at' => $session->last_validated_at?->toIso8601String(),
            'last_validation_error' => $session->last_validation_error,
            'updated_at' => $session->updated_at?->toIso8601String(),
        ];
    }
}
