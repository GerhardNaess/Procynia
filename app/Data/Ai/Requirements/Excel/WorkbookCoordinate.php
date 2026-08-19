<?php

namespace App\Data\Ai\Requirements\Excel;

/**
 * The one place that turns a position in a workbook into an identity, and back.
 *
 * Two separate strings come out of here and they must not be confused:
 *
 * - the SOURCE KEY (`sheet:0:cell:D17`) is machine identity. It uses the sheet's INDEX, because a
 *   sheet can be renamed and a sheet name may legally contain ':' and '!' — the very characters a
 *   key is delimited by. It is what gets persisted as source_element_key.
 * - the LABEL (`Kravspesifikasjon!B17:G18`) is what a human reads. It uses the sheet NAME because
 *   that is what the user sees in Excel. It is never used to look anything up.
 *
 * Neither is ever derived from a cell's value: identity is positional, so two cells with identical
 * text keep different keys, and rewording a cell does not move its provenance.
 */
final class WorkbookCoordinate
{
    public const ELEMENT_TYPE_CELL = 'sheet_cell';

    public const ELEMENT_TYPE_ROW = 'sheet_row';

    public const ELEMENT_TYPE_RANGE = 'sheet_range';

    /** 0 => A, 25 => Z, 26 => AA. */
    public static function columnLetter(int $columnIndex): string
    {
        $letter = '';
        $index = max(0, $columnIndex);

        do {
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $letter;
    }

    /** A => 0, Z => 25, AA => 26. */
    public static function columnIndex(string $columnLetter): int
    {
        $index = 0;

        foreach (str_split(strtoupper($columnLetter)) as $character) {
            $index = $index * 26 + (ord($character) - 64);
        }

        return $index - 1;
    }

    public static function cellReference(int $columnIndex, int $row): string
    {
        return self::columnLetter($columnIndex).$row;
    }

    public static function cellKey(int $sheetIndex, string $coordinate): string
    {
        return sprintf('sheet:%d:cell:%s', $sheetIndex, $coordinate);
    }

    public static function rowKey(int $sheetIndex, int $row): string
    {
        return sprintf('sheet:%d:row:%d', $sheetIndex, $row);
    }

    /** @param  string  $ref  an A1-style range such as 'B17:G18' */
    public static function rangeKey(int $sheetIndex, string $ref): string
    {
        return sprintf('sheet:%d:range:%s', $sheetIndex, $ref);
    }

    /** The human-facing 'Kravspesifikasjon!B17:G18' form. Display only — never an identity. */
    public static function label(string $sheetName, string $ref): string
    {
        return sprintf('%s!%s', $sheetName, $ref);
    }

    /**
     * Parse an A1-style range into its bounds.
     *
     * @return array{first_column_index: int, first_row: int, last_column_index: int, last_row: int}|null
     */
    public static function parseRange(string $ref): ?array
    {
        if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/i', trim($ref), $matches) !== 1) {
            return null;
        }

        return [
            'first_column_index' => self::columnIndex($matches[1]),
            'first_row' => (int) $matches[2],
            'last_column_index' => self::columnIndex($matches[3]),
            'last_row' => (int) $matches[4],
        ];
    }
}
