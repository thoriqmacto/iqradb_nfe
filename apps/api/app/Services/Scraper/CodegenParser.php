<?php

namespace App\Services\Scraper;

/**
 * Converts pasted Playwright Codegen output into structured recipe actions.
 *
 * SECURITY — read before changing anything here.
 *
 * The pasted text is INPUT DATA. It is never executed. This class does string
 * matching with anchored regular expressions and nothing else:
 *
 *   - no eval(), no create_function(), no `new Function`, no dynamic include
 *   - no shell, no filesystem, no network
 *   - no reflection, no variable-variables, no callable strings
 *
 * The parser is deliberately conservative and whole-line: a statement either
 * matches one of the exact shapes below and becomes a typed action, or it is
 * reported as unsupported for the user to configure by hand. Partial or
 * "best effort" interpretation is a correctness hazard here — a
 * misunderstood line would silently drive the browser somewhere unintended —
 * so ambiguity always loses.
 */
class CodegenParser
{
    /** Chained calls we can interpret once the locator is understood. */
    private const TERMINALS = ['click', 'fill', 'selectOption', 'press', 'check', 'uncheck'];

    public function __construct(private readonly ScdbUrlGuard $urls) {}

    /**
     * @return array{actions: list<array<string, mixed>>, unsupported: list<array{line: int, source: string, reason: string}>}
     */
    public function parse(string $source): array
    {
        $actions = [];
        $unsupported = [];

        // Codegen writes a popup handoff as a three-line sandwich:
        //
        //   const page1Promise = page.waitForEvent('popup');
        //   await page.getByRole('button', { name: 'Exports' }).click();
        //   const page1 = await page1Promise;
        //
        // and every following statement addresses `page1` instead of `page`.
        // SCDB's export wizard does this twice, so a parser that only knows
        // about `page` throws away the entire useful part of a recording.
        $currentPage = 'page';
        $popupPromises = [];
        $downloadPromises = [];
        $pendingPopup = false;
        $pendingDownload = false;

        foreach ($this->statements($source) as [$lineNumber, $statement]) {
            // const <var> = <page>.waitForEvent('popup' | 'download');
            if (preg_match(
                '/^const\s+(\w+)\s*=\s*(\w+)\.waitForEvent\(\s*[\'"](popup|download)[\'"]\s*\)$/u',
                $statement,
                $m
            ) === 1) {
                if ($m[3] === 'popup') {
                    $popupPromises[$m[1]] = $m[2];
                    $pendingPopup = $pendingPopup || $m[2] === $currentPage;
                } else {
                    $downloadPromises[$m[1]] = $m[2];
                    $pendingDownload = $pendingDownload || $m[2] === $currentPage;
                }

                continue;
            }

            // const <var> = await <promiseVar>;  — closes one of the sandwiches.
            if (preg_match('/^const\s+(\w+)\s*=\s*await\s+(\w+)$/u', $statement, $m) === 1) {
                if (isset($popupPromises[$m[2]])) {
                    // Everything after this addresses the popup.
                    $currentPage = $m[1];

                    continue;
                }

                if (isset($downloadPromises[$m[2]])) {
                    continue;
                }
                // Not a sandwich we recognise — fall through and be rejected.
            }

            $parsed = $this->parseStatement($statement, $currentPage);

            if ($parsed === null) {
                continue; // Ignorable noise (imports, test scaffolding, comments).
            }

            if (isset($parsed['reason'])) {
                $unsupported[] = [
                    'line' => $lineNumber,
                    'source' => $statement,
                    'reason' => $parsed['reason'],
                ];

                continue;
            }

            $action = $parsed['action'];

            // A click wrapped in a download sandwich IS the download step. The
            // runner has to arm the listener before clicking, so recognising it
            // here saves the user having to remember to flag it by hand.
            if ($pendingDownload && $action['type'] === 'click') {
                $action['type'] = 'download';
                $pendingDownload = false;
            } elseif ($pendingPopup && $action['type'] === 'click') {
                $action['opensPopup'] = true;
                $pendingPopup = false;
            }

            $actions[] = $action;
        }

        return ['actions' => $actions, 'unsupported' => $unsupported];
    }

    /**
     * Split pasted text into trimmed, semicolon-free statements, dropping
     * comments and obvious test scaffolding.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function statements(string $source): array
    {
        $out = [];
        $lines = preg_split('/\R/', $source) ?: [];

        foreach ($lines as $i => $raw) {
            $line = trim($raw);

            if ($line === '') {
                continue;
            }

            // Comments and block-comment bodies.
            if (str_starts_with($line, '//') || str_starts_with($line, '/*') || str_starts_with($line, '*')) {
                continue;
            }

            // Scaffolding Codegen emits around the interesting statements.
            if (preg_match('/^(import\s|const\s*\{|require\(|test\(|test\.|\}\)?;?$|\{$|\(async|await\s+browser|await\s+context\.close|await\s+browser\.close)/', $line) === 1) {
                continue;
            }

            $out[] = [$i + 1, rtrim($line, ';')];
        }

        return $out;
    }

    /**
     * @return array{action: array<string, mixed>}|array{reason: string}|null
     */
    private function parseStatement(string $statement, string $currentPage = 'page'): ?array
    {
        $pageVar = preg_quote($currentPage, '/');
        // Strip a leading `await ` — everything Codegen emits for actions has one.
        $body = preg_replace('/^await\s+/', '', $statement) ?? $statement;

        // `page.goto('https://...')`
        if (preg_match('/^'.$pageVar.'\.goto\(\s*'.self::STR.'\s*\)$/u', $body, $m) === 1) {
            $url = $this->unquote($m[1]);
            $reason = $this->urls->reject($url);

            if ($reason !== null) {
                return ['reason' => $reason];
            }

            return ['action' => ['type' => 'goto', 'url' => $url]];
        }

        // `page.waitForLoadState('networkidle')` — state optional.
        if (preg_match('/^'.$pageVar.'\.waitForLoadState\(\s*(?:'.self::STR.'\s*)?\)$/u', $body, $m) === 1) {
            $state = isset($m[1]) ? $this->unquote($m[1]) : 'load';

            if (! in_array($state, RecipeValidator::LOAD_STATES, true)) {
                return ['reason' => sprintf('Unsupported load state "%s".', $state)];
            }

            return ['action' => ['type' => 'waitForLoadState', 'state' => $state]];
        }

        // `page.waitForURL('...')`
        if (preg_match('/^'.$pageVar.'\.waitForURL\(\s*'.self::STR.'\s*\)$/u', $body, $m) === 1) {
            $pattern = $this->unquote($m[1]);

            if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $pattern) === 1) {
                $reason = $this->urls->reject(str_replace('*', 'x', $pattern));

                if ($reason !== null) {
                    return ['reason' => $reason];
                }
            }

            return ['action' => ['type' => 'waitForURL', 'url' => $pattern]];
        }

        // Everything else must be `<currentPage>.<locator>(...).<terminal>(...)`.
        if (! str_starts_with($body, $currentPage.'.')) {
            return ['reason' => sprintf(
                'Expected a statement on `%s`. If this addresses another window, the click that '
                .'opened it has to be recorded as a popup step first.',
                $currentPage
            )];
        }

        // Reject the dangerous surface explicitly, with a useful message,
        // before falling through to the generic "unsupported" case.
        foreach (['evaluate', 'evaluateHandle', 'addScriptTag', 'addInitScript', 'route', 'exposeFunction', 'setContent', '$eval', '$$eval'] as $forbidden) {
            // Matched on ANY page variable, not just the current one: a popup
            // handle must not become a way around this.
            if (preg_match('/(?:^|[^A-Za-z0-9_$])[A-Za-z0-9_$]*\.'.preg_quote($forbidden, '/').'\(/u', $body) === 1) {
                return ['reason' => sprintf('`%s` is not allowed — recipes may not run JavaScript.', $forbidden)];
            }
        }

        $split = $this->splitLocatorAndTerminal($body);

        if ($split === null) {
            return ['reason' => 'Could not identify a supported action on this line.'];
        }

        [$locatorExpr, $terminal, $argsExpr] = $split;

        $locator = $this->parseLocator($locatorExpr, $currentPage);

        if ($locator === null) {
            return ['reason' => 'Unsupported or ambiguous locator. Configure this step manually.'];
        }

        return $this->buildAction($locator, $terminal, $argsExpr);
    }

    /**
     * @param  array<string, mixed>  $locator
     * @return array{action: array<string, mixed>}|array{reason: string}
     */
    private function buildAction(array $locator, string $terminal, string $argsExpr): array
    {
        switch ($terminal) {
            case 'click':
                return ['action' => ['type' => 'click', 'locator' => $locator]];

            case 'check':
            case 'uncheck':
                // Semantically a click on a checkbox; the runner has no separate verb.
                return ['action' => ['type' => 'click', 'locator' => $locator]];

            case 'fill':
            case 'press':
            case 'selectOption':
                if (preg_match('/^\s*'.self::STR.'\s*$/u', $argsExpr, $m) !== 1) {
                    return ['reason' => sprintf(
                        '`%s` is only supported with a single plain string argument.',
                        $terminal
                    )];
                }

                return ['action' => [
                    'type' => $terminal,
                    'locator' => $locator,
                    'value' => $this->unquote($m[1]),
                ]];
        }

        return ['reason' => sprintf('Unsupported action `%s`.', $terminal)];
    }

    /**
     * Split `page.getByRole('link', { name: 'X' }).click()` into its locator
     * expression, terminal method, and argument text — tracking quote state so
     * a dot or paren inside a string does not confuse the split.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function splitLocatorAndTerminal(string $body): ?array
    {
        $depth = 0;
        $quote = null;
        $length = strlen($body);
        $lastCallStart = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++; // Skip the escaped character.

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;

                continue;
            }

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;

                continue;
            }

            if ($char === ')' || $char === ']' || $char === '}') {
                $depth--;

                continue;
            }

            // A dot at depth 0 starts a new chained call.
            if ($char === '.' && $depth === 0) {
                $lastCallStart = $i;
            }
        }

        if ($lastCallStart === null || $quote !== null || $depth !== 0) {
            return null;
        }

        $locatorExpr = substr($body, 0, $lastCallStart);
        $call = substr($body, $lastCallStart + 1);

        if (preg_match('/^([A-Za-z$]+)\((.*)\)$/s', $call, $m) !== 1) {
            return null;
        }

        $terminal = $m[1];

        if (! in_array($terminal, self::TERMINALS, true)) {
            return null;
        }

        return [$locatorExpr, $terminal, $m[2]];
    }

    /**
     * Interpret a locator expression. Chained refinements are limited to
     * `.first()`, `.last()` and `.nth(n)`; anything else (`.filter()`,
     * `.locator()` chains) is rejected as ambiguous.
     *
     * @return array<string, mixed>|null
     */
    private function parseLocator(string $expr, string $currentPage = 'page'): ?array
    {
        $expr = trim($expr);
        $nth = null;
        $hasText = null;

        // Peel trailing refinements right to left.
        while (true) {
            if (preg_match('/^(.*)\.first\(\)$/s', $expr, $m) === 1) {
                $nth ??= 0;
                $expr = $m[1];

                continue;
            }

            if (preg_match('/^(.*)\.nth\(\s*(\d{1,3})\s*\)$/s', $expr, $m) === 1) {
                $nth ??= (int) $m[2];
                $expr = $m[1];

                continue;
            }

            // `.filter({ hasText: 'x' })` — how Codegen records "the grid row
            // containing this report name". Only the hasText form is accepted;
            // `filter({ has: <locator> })` nests a locator and stays unsupported.
            if (preg_match('/^(.*)\.filter\(\s*\{\s*hasText\s*:\s*'.self::STR.'\s*\}\s*\)$/su', $expr, $m) === 1) {
                $hasText ??= $this->unquote($m[2]);
                $expr = $m[1];

                continue;
            }

            break;
        }

        $locator = $this->parseLocatorBase($expr, $currentPage);

        if ($locator === null) {
            return null;
        }

        if ($hasText !== null) {
            $locator['hasText'] = $hasText;
        }

        if ($nth !== null) {
            $locator['nth'] = $nth;
        }

        return $locator;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseLocatorBase(string $expr, string $currentPage = 'page'): ?array
    {
        $pageVar = preg_quote($currentPage, '/');
        // page.getByRole('button', { name: 'Export', exact: true })
        if (preg_match('/^'.$pageVar.'\.getByRole\(\s*'.self::STR.'\s*(?:,\s*\{(.*)\}\s*)?\)$/su', $expr, $m) === 1) {
            $locator = ['strategy' => 'role', 'role' => $this->unquote($m[1])];
            $options = $m[2] ?? '';

            if ($options !== '') {
                if (preg_match('/\bname\s*:\s*'.self::STR.'/u', $options, $n) === 1) {
                    $locator['name'] = $this->unquote($n[1]);
                } elseif (preg_match('/\bname\s*:/u', $options) === 1) {
                    // A regex or variable name — not something we can store safely.
                    return null;
                }

                if (preg_match('/\bexact\s*:\s*(true|false)/u', $options, $e) === 1) {
                    $locator['exact'] = $e[1] === 'true';
                }

                // Any other option (hasText, has, checked…) means we would be
                // dropping meaning. Refuse instead.
                $known = preg_replace('/\b(name|exact)\s*:\s*(?:'.self::STR.'|true|false)\s*,?/u', '', $options);

                if (trim((string) $known, " \t,") !== '') {
                    return null;
                }
            }

            return $locator;
        }

        foreach ([
            'getByLabel' => ['label', 'label'],
            'getByText' => ['text', 'text'],
            'getByPlaceholder' => ['placeholder', 'placeholder'],
            'getByTestId' => ['testId', 'testId'],
            'getByTitle' => ['text', 'text'],
        ] as $method => [$strategy, $key]) {
            $pattern = '/^'.$pageVar.'\.'.preg_quote($method, '/').'\(\s*'.self::STR.'\s*(?:,\s*\{(.*)\}\s*)?\)$/su';

            if (preg_match($pattern, $expr, $m) === 1) {
                $locator = ['strategy' => $strategy, $key => $this->unquote($m[1])];

                if (! empty($m[2]) && preg_match('/\bexact\s*:\s*(true|false)/u', $m[2], $e) === 1) {
                    $locator['exact'] = $e[1] === 'true';
                }

                return $locator;
            }
        }

        // page.locator('#ctl00_Main_btnExport') — CSS is the fallback of last resort.
        if (preg_match('/^'.$pageVar.'\.locator\(\s*'.self::STR.'\s*\)$/su', $expr, $m) === 1) {
            $css = $this->unquote($m[1]);

            // Playwright's locator() also accepts its own engine syntax
            // (`text=`, `xpath=`, `>>` chaining). Those are not plain CSS, so
            // storing them as `css` would be a lie.
            if (preg_match('#^(text=|xpath=|//|\.\./)#', $css) === 1 || str_contains($css, '>>')) {
                return null;
            }

            return ['strategy' => 'css', 'css' => $css];
        }

        return null;
    }

    /** Matches a single-, double- or backtick-quoted string with escapes. */
    private const STR = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`(?:[^`\\\\]|\\\\.)*`)';

    /**
     * Strip the surrounding quotes and unescape. This is plain string work —
     * it never interprets the contents as code.
     */
    private function unquote(string $quoted): string
    {
        $inner = substr($quoted, 1, -1);

        return preg_replace_callback(
            '/\\\\(.)/',
            static fn (array $m): string => match ($m[1]) {
                'n' => "\n",
                't' => "\t",
                'r' => "\r",
                '0' => "\0",
                default => $m[1],
            },
            $inner
        ) ?? $inner;
    }
}
