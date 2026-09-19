<?php

namespace App\Services\Import;

use Generator;

/**
 * A downloaded SCDB report, read as a header row plus data rows.
 *
 * CSV and XLSX differ enormously in how they are parsed and not at all in what
 * the importer needs from them, so the importer depends on this and never on a
 * concrete format.
 */
interface TabularReader
{
    /**
     * The header row, trimmed and de-duplicated.
     *
     * @return list<string>
     *
     * @throws ReportReadException
     */
    public function headers(string $path): array;

    /**
     * Data rows as header-keyed associative arrays.
     *
     * Yields `[int $rowNumber, array<string, string> $row, list<string> $errors]`.
     * `$rowNumber` is 1-based over data rows, so row 1 is the first line after
     * the header — which is what a user counting rows in Excel expects.
     *
     * @return Generator<int, array{0: int, 1: array<string, string>, 2: list<string>}>
     *
     * @throws ReportReadException
     */
    public function rows(string $path): Generator;

    /**
     * Count data rows without materialising them.
     *
     * @throws ReportReadException
     */
    public function countRows(string $path): int;
}
