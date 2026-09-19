<?php

namespace App\Services\Import;

/**
 * Picks the reader for a downloaded report by its extension.
 *
 * Adding a format means adding a reader and one entry here — the importer, the
 * preview endpoint and the job all go through this and none of them knows
 * which format they are looking at.
 */
class TabularReaderFactory
{
    public function __construct(
        private readonly CsvReader $csv,
        private readonly XlsxReader $xlsx,
    ) {}

    /** Extensions that can be parsed, staged and imported. */
    public const IMPORTABLE = ['csv', 'xlsx'];

    public function for(string $path): TabularReader
    {
        return $this->forExtension(strtolower(pathinfo($path, PATHINFO_EXTENSION)));
    }

    public function forExtension(string $extension): TabularReader
    {
        return match (strtolower(ltrim($extension, '.'))) {
            'csv' => $this->csv,
            'xlsx' => $this->xlsx,
            default => throw new ReportReadException(sprintf(
                'No reader for .%s files. Importable formats are: %s.',
                $extension === '' ? '(none)' : $extension,
                implode(', ', self::IMPORTABLE),
            )),
        };
    }

    public static function supports(string $extension): bool
    {
        return in_array(strtolower(ltrim($extension, '.')), self::IMPORTABLE, true);
    }
}
