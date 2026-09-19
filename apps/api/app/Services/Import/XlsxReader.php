<?php

namespace App\Services\Import;

use Generator;
use XMLReader;
use ZipArchive;

/**
 * Reads SCDB report XLSX files with ext-zip and ext-xmlreader only.
 *
 * SCDB's export wizard offers XLSX for reports that have no CSV option, so
 * "download it and stop" was not enough — those datasets could never be
 * imported at all. A spreadsheet library would solve far more than this needs
 * (styling, formulas, charts, writing) and the repo's rule is to add a runtime
 * package only when it is genuinely necessary, so this reads the small,
 * well-specified subset that a report export actually uses:
 *
 *   - the first worksheet, in workbook order
 *   - shared strings, including rich-text runs split across <r><t> elements
 *   - inline strings, booleans, numbers, and formula results
 *   - gaps: `<c r="C1">` after A1 means B1 is empty, and it must stay empty or
 *     every later column shifts left
 *   - date-formatted cells, converted from Excel's serial numbers
 *
 * Streaming throughout: XMLReader pulls one node at a time out of the zip, so
 * a large report never lands in memory as a DOM.
 *
 * SECURITY: the file arrives from SCDB, so it is untrusted input. External
 * entities are never substituted and the network is never touched
 * (LIBXML_NONET), and the archive's declared uncompressed size is checked
 * before anything is read, so a zip bomb is refused rather than expanded.
 */
class XlsxReader implements TabularReader
{
    use NormalisesHeaders;

    /** Built-in numFmt ids that mean "this number is a date or a time". */
    private const DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

    /** Days between the Excel epoch (1899-12-30) and the Unix epoch. */
    private const EXCEL_EPOCH_OFFSET = 25569;

    /** @return list<string> */
    public function headers(string $path): array
    {
        foreach ($this->rawRows($path) as $row) {
            return $this->normaliseHeaders($row);
        }

        throw new ReportReadException('The XLSX file has no rows.');
    }

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
            throw new ReportReadException('The XLSX file has no rows.');
        }
    }

    public function countRows(string $path): int
    {
        $total = 0;

        foreach ($this->rows($path) as $_) {
            $total++;
        }

        return $total;
    }

    /**
     * Rows of the first worksheet, as flat lists of strings, blanks included.
     *
     * @return Generator<int, list<string>>
     */
    private function rawRows(string $path): Generator
    {
        $zip = $this->open($path);

        try {
            $sheet = $this->firstSheetPath($zip);
            $shared = $this->sharedStrings($path, $zip);
            $dateStyles = $this->dateStyles($path, $zip);
        } finally {
            $zip->close();
        }

        $reader = $this->reader($path, $sheet);

        try {
            $row = [];
            $column = 0;

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
                    $row = [];
                    $column = 0;

                    if ($reader->isEmptyElement) {
                        continue;
                    }
                }

                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'c') {
                    // `r` is the cell reference (e.g. "C7"); its column decides
                    // where this value belongs, so skipped cells stay blank.
                    $index = $this->columnIndex((string) $reader->getAttribute('r'));

                    if ($index !== null) {
                        while ($column < $index) {
                            $row[$column++] = '';
                        }
                    }

                    $row[$column++] = $this->cellValue($reader, $shared, $dateStyles);

                    continue;
                }

                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'row') {
                    $meaningful = array_filter($row, static fn (string $v): bool => trim($v) !== '');

                    // A wholly empty row is spacing, not data — the CSV reader
                    // drops these too, so the two formats count rows alike.
                    if ($meaningful !== []) {
                        yield array_values($row);
                    }

                    $row = [];
                    $column = 0;
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Read one `<c>` element, including its child `<v>` or `<is>`.
     *
     * @param  array<int, string>  $shared
     * @param  array<int, bool>  $dateStyles
     */
    private function cellValue(XMLReader $reader, array $shared, array $dateStyles): string
    {
        $type = (string) $reader->getAttribute('t');
        $styleAttribute = $reader->getAttribute('s');
        $style = $styleAttribute === null ? null : (int) $styleAttribute;

        if ($reader->isEmptyElement) {
            return '';
        }

        // readInnerXml would hand back markup; walk the children instead so the
        // text of a rich-text inline string is concatenated the same way a
        // shared one is.
        $value = '';
        $depth = $reader->depth;
        $inValue = false;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }

            if ($reader->nodeType === XMLReader::ELEMENT && ($reader->name === 'v' || $reader->name === 't')) {
                $inValue = true;

                continue;
            }

            if ($reader->nodeType === XMLReader::END_ELEMENT && ($reader->name === 'v' || $reader->name === 't')) {
                $inValue = false;

                continue;
            }

            if ($inValue && in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                $value .= $reader->value;
            }
        }

        return $this->interpret($value, $type, $style, $shared, $dateStyles);
    }

    /**
     * @param  array<int, string>  $shared
     * @param  array<int, bool>  $dateStyles
     */
    private function interpret(string $value, string $type, ?int $style, array $shared, array $dateStyles): string
    {
        if ($value === '') {
            return '';
        }

        if ($type === 's') {
            // A shared-string index that is not in the table means a malformed
            // file; an empty cell is a safer reading than a fatal error.
            return $shared[(int) $value] ?? '';
        }

        if ($type === 'e') {
            // #N/A and friends. Keep them visible rather than pretending the
            // cell was blank — a rejected row is better than a wrong one.
            return $value;
        }

        if ($type === 'b') {
            return $value === '1' ? 'TRUE' : 'FALSE';
        }

        // inlineStr and str (a formula's cached result) are already text.
        if ($type === 'inlineStr' || $type === 'str' || $type === 'd') {
            return $value;
        }

        if ($style !== null && ($dateStyles[$style] ?? false) && is_numeric($value)) {
            return $this->excelSerialToDate((float) $value);
        }

        return $value;
    }

    /**
     * Excel stores a date as days since 1899-12-30 (the offset already absorbs
     * the 1900 leap-year bug for every serial a report will contain).
     */
    private function excelSerialToDate(float $serial): string
    {
        $timestamp = (int) round(($serial - self::EXCEL_EPOCH_OFFSET) * 86400);

        // A time-only cell is a fraction of a day; a whole number is a date.
        $format = fmod($serial, 1.0) === 0.0 ? 'Y-m-d' : 'Y-m-d H:i:s';

        return gmdate($format, $timestamp);
    }

    /** "C7" -> 2. Null when the reference is missing or unparseable. */
    private function columnIndex(string $reference): ?int
    {
        if (! preg_match('/^([A-Z]+)/', strtoupper($reference), $matches)) {
            return null;
        }

        $index = 0;

        foreach (str_split($matches[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * The shared string table, which most text cells point into.
     *
     * @return array<int, string>
     */
    private function sharedStrings(string $path, ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $reader = $this->reader($path, 'xl/sharedStrings.xml');
        $strings = [];

        try {
            $current = null;

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si') {
                    $current = '';

                    if ($reader->isEmptyElement) {
                        $strings[] = '';
                        $current = null;
                    }

                    continue;
                }

                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'si') {
                    $strings[] = (string) $current;
                    $current = null;

                    continue;
                }

                // <si><r><t>bold bit</t></r><r><t> rest</t></r></si> — a run of
                // differently formatted pieces is still one logical string.
                if ($current !== null && in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                    $current .= $reader->value;
                }
            }
        } finally {
            $reader->close();
        }

        return $strings;
    }

    /**
     * Which cell-format indexes mean "this number is a date".
     *
     * @return array<int, bool>
     */
    private function dateStyles(string $path, ZipArchive $zip): array
    {
        if ($zip->locateName('xl/styles.xml') === false) {
            return [];
        }

        $reader = $this->reader($path, 'xl/styles.xml');
        $custom = [];
        $styles = [];

        try {
            $inCellXfs = false;

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'numFmt') {
                    $code = (string) $reader->getAttribute('formatCode');

                    // No format string says "date"; the presence of y/d/m or a
                    // time part is how every reader decides this.
                    if (preg_match('/[dmyhs]/i', preg_replace('/\[[^\]]*\]|"[^"]*"/', '', $code) ?? '')) {
                        $custom[(int) $reader->getAttribute('numFmtId')] = true;
                    }

                    continue;
                }

                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'cellXfs') {
                    $inCellXfs = true;

                    continue;
                }

                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'cellXfs') {
                    break;
                }

                if ($inCellXfs && $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'xf') {
                    $id = (int) $reader->getAttribute('numFmtId');
                    $styles[] = in_array($id, self::DATE_FORMATS, true) || ($custom[$id] ?? false);
                }
            }
        } finally {
            $reader->close();
        }

        return $styles;
    }

    /**
     * The path of the sheet the workbook lists first.
     *
     * Not "sheet1.xml": the file names are arbitrary and their numbering does
     * not have to match the tab order, so the relationship has to be followed.
     */
    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false) {
            throw new ReportReadException('That file is not a readable XLSX workbook.');
        }

        $id = null;

        if (preg_match('/<sheet\b[^>]*r:id="([^"]+)"/', $workbook, $matches)) {
            $id = $matches[1];
        }

        if ($id !== null && $rels !== false && preg_match(
            '/<Relationship\b[^>]*Id="'.preg_quote($id, '/').'"[^>]*Target="([^"]+)"/',
            $rels,
            $matches
        )) {
            $target = ltrim($matches[1], '/');
            $target = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;

            if ($zip->locateName($target) !== false) {
                return $target;
            }
        }

        if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
            return 'xl/worksheets/sheet1.xml';
        }

        throw new ReportReadException('That XLSX file contains no worksheet.');
    }

    private function open(string $path): ZipArchive
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ReportReadException('The XLSX file could not be read.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ReportReadException('That file is not a readable XLSX workbook.');
        }

        $this->assertNotABomb($zip);

        return $zip;
    }

    /**
     * Refuse an archive that claims to expand far beyond the download limit.
     *
     * The entries are read as streams, so nothing is written to disk, but a
     * deeply compressed sheet would still be walked node by node forever.
     */
    private function assertNotABomb(ZipArchive $zip): void
    {
        $limit = (int) config('scraper.max_download_bytes', 0);

        if ($limit <= 0) {
            return;
        }

        // XLSX is XML, which compresses roughly tenfold; allow generous headroom
        // over the download limit and reject only the absurd.
        $allowed = $limit * 50;
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $total += (int) ($stat['size'] ?? 0);

            if ($total > $allowed) {
                $zip->close();

                throw new ReportReadException('That XLSX file expands to more data than the import limit allows.');
            }
        }
    }

    private function reader(string $path, string $entry): XMLReader
    {
        $reader = new XMLReader;

        // LIBXML_NONET: no network fetch for any declaration in the document.
        // Entity substitution stays off (XMLReader's default), so an entity
        // bomb never expands.
        if (! $reader->open('zip://'.$path.'#'.$entry, null, LIBXML_NONET)) {
            throw new ReportReadException('That XLSX file could not be parsed.');
        }

        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

        return $reader;
    }
}
