<?php

namespace App\Services\Import;

use Generator;
use SplFileObject;

/**
 * Reads SCDB report CSVs with PHP's native CSV handling.
 *
 * SplFileObject::READ_CSV already deals correctly with quoted commas, quoted
 * newlines, and escaped quotes, so no CSV dependency is warranted. What it does
 * NOT handle, and what this class adds:
 *
 *   - a UTF-8 BOM in front of the first header
 *   - CRLF line endings (READ_AHEAD + DROP_NEW_LINE leaves a stray \r)
 *   - whitespace padding around headers
 *
 * Header normalisation and row alignment are shared with the XLSX reader via
 * NormalisesHeaders, so both formats name blank columns, disambiguate
 * duplicates, and report short or long rows identically.
 */
class CsvReader implements TabularReader
{
    use NormalisesHeaders;

    /**
     * Read the header row, normalised.
     *
     * @return list<string>
     *
     * @throws ReportReadException
     */
    public function headers(string $path): array
    {
        foreach ($this->rawRows($path) as $row) {
            return $this->normaliseHeaders($row);
        }

        throw new ReportReadException('The CSV file is empty.');
    }

    /**
     * Stream data rows as header-keyed associative arrays.
     *
     * Yields `[int $rowNumber, array<string, string> $row, list<string> $errors]`.
     * `$rowNumber` is 1-based over data rows, so row 1 is the first line after
     * the header — which is what a user counting rows in Excel expects.
     *
     * @return Generator<int, array{0: int, 1: array<string, string>, 2: list<string>}>
     *
     * @throws ReportReadException
     */
    public function rows(string $path): Generator
    {
        $headers = null;
        $count = 0;

        foreach ($this->rawRows($path) as $raw) {
            if ($headers === null) {
                $headers = $this->normaliseHeaders($raw);

                continue;
            }

            $count++;
            [$values, $errors] = $this->alignRow($headers, $raw);

            yield [$count, $values, $errors];
        }

        if ($headers === null) {
            throw new ReportReadException('The CSV file is empty.');
        }
    }

    /**
     * Count data rows without materialising them.
     *
     * @throws ReportReadException
     */
    public function countRows(string $path): int
    {
        $total = 0;

        foreach ($this->rows($path) as $_) {
            $total++;
        }

        return $total;
    }

    /**
     * Raw CSV records straight from SplFileObject, BOM stripped.
     *
     * @return Generator<int, list<string|null>>
     *
     * @throws ReportReadException
     */
    private function rawRows(string $path): Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ReportReadException('The CSV file could not be read.');
        }

        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl(',', '"', '\\');

        $first = true;

        foreach ($file as $record) {
            // SplFileObject yields [null] for a trailing blank line.
            if (! is_array($record) || $record === [null]) {
                continue;
            }

            if ($first) {
                $first = false;

                if (isset($record[0]) && is_string($record[0])) {
                    $record[0] = $this->stripBom($record[0]);
                }
            }

            // CRLF files leave a \r on the last cell of every row.
            $last = array_key_last($record);

            if ($last !== null && is_string($record[$last])) {
                $record[$last] = rtrim($record[$last], "\r");
            }

            // Skip rows that are entirely empty.
            $meaningful = array_filter(
                $record,
                static fn ($v): bool => is_string($v) && trim($v) !== ''
            );

            if ($meaningful === []) {
                continue;
            }

            yield $record;
        }
    }
}
