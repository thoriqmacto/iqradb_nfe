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
 *   - duplicate header names, which would otherwise collide in an assoc array
 *   - short/long rows, which are reported rather than silently zipped
 */
class CsvReader
{
    public const BOM = "\xEF\xBB\xBF";

    /**
     * Read the header row, normalised.
     *
     * @return list<string>
     *
     * @throws CsvReadException
     */
    public function headers(string $path): array
    {
        foreach ($this->rawRows($path) as $row) {
            return $this->normaliseHeaders($row);
        }

        throw new CsvReadException('The CSV file is empty.');
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
     * @throws CsvReadException
     */
    public function rows(string $path): Generator
    {
        $headers = null;
        $count = $header = 0;

        foreach ($this->rawRows($path) as $raw) {
            if ($headers === null) {
                $headers = $this->normaliseHeaders($raw);
                $header = count($headers);

                continue;
            }

            $count++;
            $errors = [];
            $values = array_map(
                static fn ($v): string => is_string($v) ? trim($v) : '',
                $raw
            );

            $actual = count($values);

            if ($actual < $header) {
                $errors[] = sprintf('Row has %d columns, expected %d.', $actual, $header);
                $values = array_pad($values, $header, '');
            } elseif ($actual > $header) {
                $errors[] = sprintf('Row has %d columns, expected %d; extra values ignored.', $actual, $header);
                $values = array_slice($values, 0, $header);
            }

            yield [$count, array_combine($headers, $values), $errors];
        }

        if ($headers === null) {
            throw new CsvReadException('The CSV file is empty.');
        }
    }

    /**
     * Count data rows without materialising them.
     *
     * @throws CsvReadException
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
     * @throws CsvReadException
     */
    private function rawRows(string $path): Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new CsvReadException('The CSV file could not be read.');
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

    public function stripBom(string $value): string
    {
        return str_starts_with($value, self::BOM)
            ? substr($value, strlen(self::BOM))
            : $value;
    }

    /**
     * Trim headers and disambiguate duplicates so `array_combine` is safe and
     * no column silently shadows another.
     *
     * @param  list<string|null>  $raw
     * @return list<string>
     */
    private function normaliseHeaders(array $raw): array
    {
        $headers = [];
        $seen = [];

        foreach ($raw as $i => $value) {
            $name = is_string($value) ? trim($this->stripBom($value)) : '';

            if ($name === '') {
                $name = 'column_'.($i + 1);
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                $seen[$key]++;
                $name .= ' ('.$seen[$key].')';
            } else {
                $seen[$key] = 1;
            }

            $headers[] = $name;
        }

        return $headers;
    }
}
