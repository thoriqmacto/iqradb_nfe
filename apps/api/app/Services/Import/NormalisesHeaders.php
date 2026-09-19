<?php

namespace App\Services\Import;

/**
 * Header handling shared by every report format.
 *
 * Both readers hand their headers to `array_combine`, so a blank or duplicated
 * column name is not a cosmetic problem: it either throws or silently shadows
 * another column. Naming them here makes that impossible.
 */
trait NormalisesHeaders
{
    public const BOM = "\xEF\xBB\xBF";

    public function stripBom(string $value): string
    {
        return str_starts_with($value, self::BOM)
            ? substr($value, strlen(self::BOM))
            : $value;
    }

    /**
     * @param  list<string|null>  $raw
     * @return list<string>
     */
    protected function normaliseHeaders(array $raw): array
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

    /**
     * Turn a raw record into header-keyed values, reporting length mismatches
     * rather than zipping them away.
     *
     * @param  list<string>  $headers
     * @param  list<string|null>  $raw
     * @return array{0: array<string, string>, 1: list<string>}
     */
    protected function alignRow(array $headers, array $raw): array
    {
        $expected = count($headers);
        $errors = [];

        $values = array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            $raw
        );

        $actual = count($values);

        if ($actual < $expected) {
            $errors[] = sprintf('Row has %d columns, expected %d.', $actual, $expected);
            $values = array_pad($values, $expected, '');
        } elseif ($actual > $expected) {
            $errors[] = sprintf('Row has %d columns, expected %d; extra values ignored.', $actual, $expected);
            $values = array_slice($values, 0, $expected);
        }

        return [array_combine($headers, $values), $errors];
    }
}
