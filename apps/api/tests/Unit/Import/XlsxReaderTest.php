<?php

namespace Tests\Unit\Import;

use App\Services\Import\ReportReadException;
use App\Services\Import\XlsxReader;
use Tests\TestCase;
use ZipArchive;

/**
 * The XLSX reader is hand-rolled, so these tests are the specification for the
 * subset it claims to support. Every fixture is a real zip built here rather
 * than a checked-in binary: what the reader is being asked to handle stays
 * readable in the diff.
 */
class XlsxReaderTest extends TestCase
{
    private XlsxReader $reader;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new XlsxReader;
        config()->set('scraper.max_download_bytes', 10 * 1024 * 1024);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * Assemble a minimal but genuine workbook.
     *
     * @param  list<string>  $sharedStrings
     */
    private function xlsx(string $sheetXml, array $sharedStrings = [], ?string $stylesXml = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $this->temporaryFiles[] = $path;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0"?><workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Report" sheetId="1" r:id="rId7"/></sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?><Relationships>'
            .'<Relationship Id="rId7" Target="worksheets/theOnlySheet.xml"/></Relationships>');

        $zip->addFromString('xl/worksheets/theOnlySheet.xml',
            '<?xml version="1.0"?><worksheet><sheetData>'.$sheetXml.'</sheetData></worksheet>');

        if ($sharedStrings !== []) {
            $zip->addFromString('xl/sharedStrings.xml',
                '<?xml version="1.0"?><sst>'.implode('', $sharedStrings).'</sst>');
        }

        if ($stylesXml !== null) {
            $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet>'.$stylesXml.'</styleSheet>');
        }

        $zip->close();

        return $path;
    }

    /** @return list<array{0: int, 1: array<string, string>, 2: list<string>}> */
    private function collect(string $path): array
    {
        return iterator_to_array($this->reader->rows($path), false);
    }

    public function test_it_reads_shared_strings_as_cell_text(): void
    {
        $path = $this->xlsx(
            '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>42</v></c></row>',
            ['<si><t>Tag</t></si>', '<si><t>Count</t></si>', '<si><t>LI-1001</t></si>'],
        );

        $this->assertSame(['Tag', 'Count'], $this->reader->headers($path));
        $this->assertSame([[1, ['Tag' => 'LI-1001', 'Count' => '42'], []]], $this->collect($path));
    }

    public function test_it_joins_rich_text_runs_into_one_string(): void
    {
        // Excel splits a cell with mixed formatting into <r> runs. Reading only
        // the first would silently truncate a report name.
        $path = $this->xlsx(
            '<row r="1"><c r="A1" t="s"><v>0</v></c></row><row r="2"><c r="A2" t="s"><v>1</v></c></row>',
            ['<si><t>Name</t></si>', '<si><r><t>COMP_RPT_</t></r><r><t>Redline markup</t></r></si>'],
        );

        $this->assertSame('COMP_RPT_Redline markup', $this->collect($path)[0][1]['Name']);
    }

    public function test_a_skipped_cell_leaves_its_column_blank(): void
    {
        // <c r="C2"> with no B2 must not shift C's value left into B.
        $path = $this->xlsx(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c>'
            .'<c r="B1" t="inlineStr"><is><t>b</t></is></c>'
            .'<c r="C1" t="inlineStr"><is><t>c</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>1</t></is></c>'
            .'<c r="C2" t="inlineStr"><is><t>3</t></is></c></row>'
        );

        $this->assertSame(['a' => '1', 'b' => '', 'c' => '3'], $this->collect($path)[0][1]);
    }

    public function test_it_reads_inline_strings_booleans_and_formula_results(): void
    {
        $path = $this->xlsx(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>text</t></is></c>'
            .'<c r="B1" t="inlineStr"><is><t>flag</t></is></c>'
            .'<c r="C1" t="inlineStr"><is><t>calc</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>hello</t></is></c>'
            .'<c r="B2" t="b"><v>1</v></c>'
            .'<c r="C2" t="str"><f>A2</f><v>hello</v></c></row>'
        );

        $this->assertSame(
            ['text' => 'hello', 'flag' => 'TRUE', 'calc' => 'hello'],
            $this->collect($path)[0][1]
        );
    }

    public function test_a_date_formatted_number_becomes_a_date(): void
    {
        // 45000 is 2023-03-15; style 1 points at the built-in date format 14.
        $path = $this->xlsx(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>when</t></is></c></row>'
            .'<row r="2"><c r="A2" s="1"><v>45000</v></c></row>',
            [],
            '<cellXfs><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs>'
        );

        $this->assertSame('2023-03-15', $this->collect($path)[0][1]['when']);
    }

    public function test_a_plain_number_is_left_alone_even_next_to_date_styles(): void
    {
        $path = $this->xlsx(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>n</t></is></c></row>'
            .'<row r="2"><c r="A2" s="0"><v>45000</v></c></row>',
            [],
            '<cellXfs><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs>'
        );

        $this->assertSame('45000', $this->collect($path)[0][1]['n']);
    }

    public function test_a_custom_date_format_counts_as_a_date(): void
    {
        $path = $this->xlsx(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>when</t></is></c></row>'
            .'<row r="2"><c r="A2" s="1"><v>45000</v></c></row>',
            [],
            '<numFmts><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts>'
            .'<cellXfs><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs>'
        );

        $this->assertSame('2023-03-15', $this->collect($path)[0][1]['when']);
    }

    public function test_blank_rows_are_skipped_so_row_numbers_match_the_csv_reader(): void
    {
        $path = $this->xlsx(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>one</t></is></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t></t></is></c></row>'
            .'<row r="4"/>'
            .'<row r="5"><c r="A5" t="inlineStr"><is><t>two</t></is></c></row>'
        );

        $this->assertSame([1, 2], array_column($this->collect($path), 0));
        $this->assertSame(2, $this->reader->countRows($path));
    }

    public function test_short_and_long_rows_are_reported_not_silently_zipped(): void
    {
        $path = $this->xlsx(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c>'
            .'<c r="B1" t="inlineStr"><is><t>b</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>1</t></is></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>1</t></is></c>'
            .'<c r="B3" t="inlineStr"><is><t>2</t></is></c>'
            .'<c r="C3" t="inlineStr"><is><t>3</t></is></c></row>'
        );

        $rows = $this->collect($path);

        $this->assertSame(['a' => '1', 'b' => ''], $rows[0][1]);
        $this->assertStringContainsString('expected 2', $rows[0][2][0]);
        $this->assertSame(['a' => '1', 'b' => '2'], $rows[1][1]);
        $this->assertStringContainsString('extra values ignored', $rows[1][2][0]);
    }

    public function test_it_follows_the_workbook_relationship_rather_than_guessing_sheet1(): void
    {
        // The fixture's sheet is called theOnlySheet.xml on purpose: a reader
        // that hardcodes sheet1.xml fails every test above, so assert the
        // headers come back to make the reason explicit.
        $path = $this->xlsx('<row r="1"><c r="A1" t="inlineStr"><is><t>only</t></is></c></row>');

        $this->assertSame(['only'], $this->reader->headers($path));
    }

    public function test_blank_and_duplicate_headers_are_named_the_same_way_csv_does(): void
    {
        $path = $this->xlsx(
            '<row r="1"><c r="A1" t="inlineStr"><is><t>Tag</t></is></c>'
            .'<c r="B1" t="inlineStr"><is><t> </t></is></c>'
            .'<c r="C1" t="inlineStr"><is><t>Tag</t></is></c></row>'
        );

        $this->assertSame(['Tag', 'column_2', 'Tag (2)'], $this->reader->headers($path));
    }

    public function test_it_rejects_a_file_that_is_not_a_workbook(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $this->temporaryFiles[] = $path;
        file_put_contents($path, 'this is not a zip');

        $this->expectException(ReportReadException::class);
        $this->reader->headers($path);
    }

    public function test_it_rejects_an_empty_workbook(): void
    {
        $this->expectException(ReportReadException::class);
        $this->reader->headers($this->xlsx(''));
    }

    public function test_an_external_entity_is_never_expanded(): void
    {
        // The file comes from SCDB, so it is untrusted input. An entity
        // referencing a local path must not be substituted into a cell.
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $this->temporaryFiles[] = $path;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0"?><workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="s" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?><Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml',
            '<?xml version="1.0"?><!DOCTYPE worksheet [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&xxe;</t></is></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $headers = [];

        try {
            $headers = $this->reader->headers($path);
        } catch (ReportReadException) {
            // Refusing the file outright is an equally correct outcome.
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertStringNotContainsString('root:', implode('', $headers));
    }
}
