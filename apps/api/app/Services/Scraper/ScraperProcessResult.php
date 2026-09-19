<?php

namespace App\Services\Scraper;

/**
 * The parsed result of one Node worker invocation.
 *
 * @param  array<string, mixed>  $data
 */
class ScraperProcessResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $status,
        public readonly array $data = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $failedStepIndex = null,
        public readonly ?array $failedAction = null,
        /** Per-frame inventory of what the page exposed when a step failed. */
        public readonly ?array $pageInventory = null,
        public readonly ?string $finalUrl = null,
        public readonly int $exitCode = 0,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
