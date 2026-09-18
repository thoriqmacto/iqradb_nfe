<?php

namespace App\Services\Scraper;

use Illuminate\Validation\ValidationException;

/**
 * Validates a structured recipe before it can be saved or launched.
 *
 * The action vocabulary is a closed set on purpose. Anything not named here is
 * rejected rather than passed through, which is what stops a recipe from ever
 * describing `page.evaluate`, a Node call, or an arbitrary Playwright method.
 */
class RecipeValidator
{
    public const SCHEMA_VERSION = 1;

    /** Actions the Node runner knows how to execute. Nothing else is accepted. */
    public const ACTIONS = [
        'goto',
        'click',
        'fill',
        'selectOption',
        'press',
        'waitForURL',
        'waitForLoadState',
        'waitForVisible',
        'download',
    ];

    /** Preferred first: user-facing locators survive ASP.NET markup churn. */
    public const STRATEGIES = ['role', 'label', 'text', 'placeholder', 'testId', 'css'];

    public const LOAD_STATES = ['load', 'domcontentloaded', 'networkidle'];

    /** Actions that address an element and therefore require a locator. */
    private const NEEDS_LOCATOR = ['click', 'fill', 'selectOption', 'press', 'waitForVisible', 'download'];

    /** Actions that carry a user-supplied value. */
    private const NEEDS_VALUE = ['fill', 'selectOption', 'press'];

    public function __construct(private readonly ScdbUrlGuard $urls) {}

    /**
     * @param  array<int, mixed>  $actions
     * @return array<int, array<string, mixed>> the normalised action list
     *
     * @throws ValidationException
     */
    public function validate(array $actions, string $attribute = 'actions'): array
    {
        $max = (int) config('scraper.max_actions', 60);

        if ($actions === []) {
            $this->fail($attribute, 'A recipe needs at least one action.');
        }

        if (count($actions) > $max) {
            $this->fail($attribute, "A recipe may not have more than {$max} actions.");
        }

        $normalised = [];
        $hasDownload = false;

        foreach (array_values($actions) as $index => $action) {
            $normalised[] = $this->validateAction($action, $index, $attribute);

            if (($action['type'] ?? null) === 'download') {
                $hasDownload = true;
            }
        }

        // Not an error: a navigation-only recipe is a legitimate smoke test.
        // The run mode decides whether a download is actually required.
        unset($hasDownload);

        return $normalised;
    }

    /**
     * @param  mixed  $action
     * @return array<string, mixed>
     */
    private function validateAction($action, int $index, string $attribute): array
    {
        $field = "{$attribute}.{$index}";

        if (! is_array($action)) {
            $this->fail($field, "Action {$index} must be an object.");
        }

        $type = $action['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::ACTIONS, true)) {
            $this->fail($field, sprintf(
                'Action %d has unsupported type "%s". Supported: %s.',
                $index,
                is_string($type) ? $type : gettype($type),
                implode(', ', self::ACTIONS)
            ));
        }

        $out = ['type' => $type];

        if ($type === 'goto') {
            $url = $action['url'] ?? null;

            if (! is_string($url)) {
                $this->fail($field, "Action {$index} (goto) needs a url.");
            }

            $reason = $this->urls->reject($url);

            if ($reason !== null) {
                $this->fail($field, "Action {$index} (goto): {$reason}");
            }

            $out['url'] = trim($url);

            return $out;
        }

        if ($type === 'waitForURL') {
            $pattern = $action['url'] ?? null;

            if (! is_string($pattern) || trim($pattern) === '') {
                $this->fail($field, "Action {$index} (waitForURL) needs a url.");
            }

            // A wait pattern may be a glob, so it is not necessarily parseable
            // as a URL. Absolute patterns still have to point at an allowed
            // host; relative ones resolve against the already-guarded origin.
            if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $pattern) === 1) {
                $probe = str_replace('*', 'x', $pattern);
                $reason = $this->urls->reject($probe);

                if ($reason !== null) {
                    $this->fail($field, "Action {$index} (waitForURL): {$reason}");
                }
            }

            $out['url'] = trim($pattern);

            return $out;
        }

        if ($type === 'waitForLoadState') {
            $state = $action['state'] ?? 'load';

            if (! is_string($state) || ! in_array($state, self::LOAD_STATES, true)) {
                $this->fail($field, sprintf(
                    'Action %d (waitForLoadState) state must be one of: %s.',
                    $index,
                    implode(', ', self::LOAD_STATES)
                ));
            }

            $out['state'] = $state;

            return $out;
        }

        if (in_array($type, self::NEEDS_LOCATOR, true)) {
            $out['locator'] = $this->validateLocator($action['locator'] ?? null, $index, $field);
        }

        if (in_array($type, self::NEEDS_VALUE, true)) {
            $value = $action['value'] ?? null;

            if (! is_string($value) || $value === '') {
                $this->fail($field, "Action {$index} ({$type}) needs a non-empty value.");
            }

            if (mb_strlen($value) > 500) {
                $this->fail($field, "Action {$index} ({$type}) value is too long.");
            }

            $out['value'] = $value;
        }

        if (isset($action['timeoutMs'])) {
            $timeout = $action['timeoutMs'];

            if (! is_int($timeout) || $timeout < 100 || $timeout > 120000) {
                $this->fail($field, "Action {$index} timeoutMs must be between 100 and 120000.");
            }

            $out['timeoutMs'] = $timeout;
        }

        return $out;
    }

    /**
     * @param  mixed  $locator
     * @return array<string, mixed>
     */
    private function validateLocator($locator, int $index, string $field): array
    {
        if (! is_array($locator)) {
            $this->fail($field, "Action {$index} needs a locator object.");
        }

        $strategy = $locator['strategy'] ?? null;

        if (! is_string($strategy) || ! in_array($strategy, self::STRATEGIES, true)) {
            $this->fail($field, sprintf(
                'Action %d has unsupported locator strategy "%s". Supported: %s.',
                $index,
                is_string($strategy) ? $strategy : gettype($strategy),
                implode(', ', self::STRATEGIES)
            ));
        }

        $out = ['strategy' => $strategy];

        $requiredKey = match ($strategy) {
            'role' => 'role',
            'label' => 'label',
            'text' => 'text',
            'placeholder' => 'placeholder',
            'testId' => 'testId',
            'css' => 'css',
        };

        $value = $locator[$requiredKey] ?? null;

        if (! is_string($value) || trim($value) === '') {
            $this->fail($field, "Action {$index} locator needs a non-empty \"{$requiredKey}\".");
        }

        if (mb_strlen($value) > 300) {
            $this->fail($field, "Action {$index} locator \"{$requiredKey}\" is too long.");
        }

        $out[$requiredKey] = $value;

        // role locators are far more robust when paired with an accessible name.
        if ($strategy === 'role' && isset($locator['name'])) {
            if (! is_string($locator['name']) || mb_strlen($locator['name']) > 300) {
                $this->fail($field, "Action {$index} locator name must be a short string.");
            }

            $out['name'] = $locator['name'];
        }

        if (isset($locator['exact'])) {
            if (! is_bool($locator['exact'])) {
                $this->fail($field, "Action {$index} locator exact must be a boolean.");
            }

            $out['exact'] = $locator['exact'];
        }

        // Disambiguates "the 3rd matching row" without needing a CSS selector.
        if (isset($locator['nth'])) {
            if (! is_int($locator['nth']) || $locator['nth'] < 0 || $locator['nth'] > 200) {
                $this->fail($field, "Action {$index} locator nth must be between 0 and 200.");
            }

            $out['nth'] = $locator['nth'];
        }

        return $out;
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    /** Describe a locator for a human-readable failure message. */
    public static function describeLocator(?array $locator): string
    {
        if ($locator === null) {
            return 'page';
        }

        return match ($locator['strategy'] ?? null) {
            'role' => isset($locator['name'])
                ? sprintf('role=%s named "%s"', $locator['role'], $locator['name'])
                : sprintf('role=%s', $locator['role']),
            'label' => sprintf('label "%s"', $locator['label']),
            'text' => sprintf('text "%s"', $locator['text']),
            'placeholder' => sprintf('placeholder "%s"', $locator['placeholder']),
            'testId' => sprintf('test id "%s"', $locator['testId']),
            'css' => sprintf('css "%s"', $locator['css']),
            default => 'element',
        };
    }
}
