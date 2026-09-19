<?php

namespace Tests\Unit\Import;

use App\Services\Import\CsvReader;
use App\Services\Import\ReportReadException;
use Tests\TestCase;

class CsvReaderTest extends TestCase
{
    private CsvReader $reader;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new CsvReader;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv').'.csv';
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /** @return list<array{0: int, 1: array<string, string>, 2: list<string>}> */
    private function collect(string $path): array
    {
        return iterator_to_array($this->reader->rows($path), false);
    }

    public function test_it_reads_a_plain_csv(): void
    {
        $path = $this->csv("Loop No,Train,Status\nL-001,Train-8,Complete\nL-002,Train-9,Pending\n");

        $this->assertSame(['Loop No', 'Train', 'Status'], $this->reader->headers($path));

        $rows = $this->collect($path);
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0][0]);
        $this->assertSame(['Loop No' => 'L-001', 'Train' => 'Train-8', 'Status' => 'Complete'], $rows[0][1]);
    }

    public function test_it_strips_a_utf8_bom_from_the_first_header(): void
    {
        $path = $this->csv(CsvReader::BOM."Loop No,Train\nL-001,Train-8\n");

        $this->assertSame(['Loop No', 'Train'], $this->reader->headers($path));
        $this->assertSame('L-001', $this->collect($path)[0][1]['Loop No']);
    }

    public function test_it_handles_quoted_commas(): void
    {
        $path = $this->csv("Loop No,Description\nL-001,\"Pump A, discharge\"\n");

        $this->assertSame('Pump A, discharge', $this->collect($path)[0][1]['Description']);
    }

    public function test_it_handles_quoted_newlines(): void
    {
        $path = $this->csv("Loop No,Notes\nL-001,\"line one\nline two\"\nL-002,plain\n");

        $rows = $this->collect($path);
        $this->assertCount(2, $rows);
        $this->assertSame("line one\nline two", $rows[0][1]['Notes']);
        $this->assertSame('plain', $rows[1][1]['Notes']);
    }

    public function test_it_handles_escaped_quotes(): void
    {
        $path = $this->csv("Loop No,Notes\nL-001,\"He said \"\"go\"\"\"\n");

        $this->assertSame('He said "go"', $this->collect($path)[0][1]['Notes']);
    }

    public function test_it_handles_crlf_line_endings(): void
    {
        $path = $this->csv("Loop No,Train\r\nL-001,Train-8\r\nL-002,Train-9\r\n");

        $this->assertSame(['Loop No', 'Train'], $this->reader->headers($path));

        $rows = $this->collect($path);
        $this->assertCount(2, $rows);
        // The \r must not survive onto the last column's value.
        $this->assertSame('Train-8', $rows[0][1]['Train']);
    }

    public function test_it_trims_whitespace_around_headers_and_values(): void
    {
        $path = $this->csv("  Loop No  ,  Train \nL-001 ,  Train-8\n");

        $this->assertSame(['Loop No', 'Train'], $this->reader->headers($path));
        $this->assertSame('L-001', $this->collect($path)[0][1]['Loop No']);
    }

    public function test_it_disambiguates_duplicate_headers(): void
    {
        $path = $this->csv("Status,Status,Status\na,b,c\n");

        $this->assertSame(['Status', 'Status (2)', 'Status (3)'], $this->reader->headers($path));

        $row = $this->collect($path)[0][1];
        $this->assertSame('a', $row['Status']);
        $this->assertSame('b', $row['Status (2)']);
        $this->assertSame('c', $row['Status (3)']);
    }

    public function test_it_names_blank_headers(): void
    {
        $path = $this->csv("Loop No,,Train\nL-001,x,Train-8\n");

        $this->assertSame(['Loop No', 'column_2', 'Train'], $this->reader->headers($path));
    }

    public function test_it_reports_short_and_long_rows_instead_of_dropping_data(): void
    {
        $path = $this->csv("A,B,C\n1,2\n1,2,3,4\n");

        $rows = $this->collect($path);

        $this->assertStringContainsString('expected 3', $rows[0][2][0]);
        $this->assertSame('', $rows[0][1]['C']);

        $this->assertStringContainsString('extra values ignored', $rows[1][2][0]);
        $this->assertSame(['A' => '1', 'B' => '2', 'C' => '3'], $rows[1][1]);
    }

    public function test_it_skips_blank_lines(): void
    {
        $path = $this->csv("A,B\n1,2\n\n\n3,4\n");

        $this->assertCount(2, $this->collect($path));
    }

    public function test_it_preserves_empty_values(): void
    {
        $path = $this->csv("A,B,C\n1,,3\n");

        $this->assertSame(['A' => '1', 'B' => '', 'C' => '3'], $this->collect($path)[0][1]);
    }

    public function test_it_rejects_an_empty_file(): void
    {
        $this->expectException(ReportReadException::class);
        $this->reader->headers($this->csv(''));
    }

    public function test_it_rejects_a_missing_file(): void
    {
        $this->expectException(ReportReadException::class);
        $this->reader->headers('/nonexistent/report.csv');
    }

    public function test_it_counts_rows(): void
    {
        $path = $this->csv("A,B\n1,2\n3,4\n5,6\n");

        $this->assertSame(3, $this->reader->countRows($path));
    }
}
