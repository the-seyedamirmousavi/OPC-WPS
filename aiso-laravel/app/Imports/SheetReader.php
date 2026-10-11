<?php

namespace App\Imports;

use App\Support\Jalali;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Header-driven access to one worksheet. Column lookup ignores case, spaces, underscores and Persian letter variants.
 * Rows are identified by their Excel row number (1-based, the header is row 1).
 */
final class SheetReader
{
    public readonly string $name;
    /** @var array<string, int> normalized column name => 1-based column index */
    private array $columns = [];
    /** @var array<string, string> normalized column name => the header as written */
    private array $displayNames = [];

    private function __construct(string $name, private readonly Worksheet $sheet)
    {
        $this->name = $name;
        $highest = $sheet->getHighestDataColumn();
        $maxCol = Coordinate::columnIndexFromString($highest);
        for ($c = 1; $c <= $maxCol; $c++) {
            $text = self::text($sheet->getCell(Coordinate::stringFromColumnIndex($c) . 1));
            if ($text === null) {
                continue;
            }
            $key = self::normalize($text);
            $this->columns[$key] ??= $c;
            $this->displayNames[$key] ??= $text;
            // a Persian header is also known under its canonical (English) name
            $canonical = FaNames::canonicalColumn($key);
            if ($canonical !== null) {
                $ck = self::normalize($canonical);
                $this->columns[$ck] ??= $c;
                $this->displayNames[$ck] ??= $text;
            }
        }
    }

    /** Finds a sheet by name, ignoring case; the Persian name of a master-template sheet is accepted too. */
    public static function find(Spreadsheet $wb, string $wanted): ?self
    {
        foreach (FaNames::sheetNames($wanted) as $name) {
            foreach ($wb->getAllSheets() as $s) {
                if (self::normalize($s->getTitle()) === self::normalize($name)) {
                    return new self($s->getTitle(), $s);
                }
            }
        }

        return null;
    }

    public static function has(Spreadsheet $wb, string $wanted): bool
    {
        return self::find($wb, $wanted) !== null;
    }

    public static function normalize(string $s): string
    {
        $out = '';
        foreach (preg_split('//u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if ($ch === "\u{200C}" || $ch === '_' || $ch === '-' || $ch === ' ' || preg_match('/^\s$/u', $ch) === 1) {
                continue;
            }
            $out .= match ($ch) {
                'ي' => 'ی', // Arabic yeh -> Persian yeh
                'ك' => 'ک', // Arabic kaf -> Persian kaf
                default => $ch,
            };
        }

        return $out;
    }

    public function hasColumn(string $column): bool
    {
        return isset($this->columns[self::normalize($column)]);
    }

    /**
     * Excel row numbers below the header that contain at least one non-empty cell.
     *
     * @return int[]
     */
    public function rows(): array
    {
        $out = [];
        $last = $this->sheet->getHighestDataRow();
        $maxCol = Coordinate::columnIndexFromString($this->sheet->getHighestDataColumn());
        for ($i = 2; $i <= $last; $i++) {
            for ($c = 1; $c <= $maxCol; $c++) {
                if (self::text($this->sheet->getCell(Coordinate::stringFromColumnIndex($c) . $i)) !== null) {
                    $out[] = $i;
                    break;
                }
            }
        }

        return $out;
    }

    public function str(int $row, string $column): ?string
    {
        $idx = $this->columns[self::normalize($column)] ?? null;

        return $idx === null ? null : self::text($this->sheet->getCell(Coordinate::stringFromColumnIndex($idx) . $row));
    }

    /**
     * Parses a number; returns null when empty. Records an error and returns null when it is not numeric.
     *
     * @param array{errors: list<array<string, mixed>>} $sink
     */
    public function number(int $row, string $column, ParsedData $sink): ?float
    {
        $idx = $this->columns[self::normalize($column)] ?? null;
        if ($idx === null) {
            return null;
        }
        $cell = $this->sheet->getCell(Coordinate::stringFromColumnIndex($idx) . $row);
        $value = $cell->getCalculatedValue();
        if (is_int($value) || is_float($value)) {
            if (!Date::isDateTime($cell)) {
                return (float) $value;
            }
        }
        $t = self::text($cell);
        if ($t === null) {
            return null;
        }
        $clean = str_replace([',', '٫'], ['', '.'], Jalali::latin($t));
        if (is_numeric($clean)) {
            return (float) $clean;
        }
        $sink->error($this->name, $row, $this->columnLabel($column), "'{$t}' is not a number");

        return null;
    }

    public function columnLabel(string $column): string
    {
        return $this->displayNames[self::normalize($column)] ?? $column;
    }

    /** Text of a cell as the user sees it, or null when empty. */
    public static function text(Cell $c): ?string
    {
        $value = $c->getCalculatedValue();
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            $s = $value ? 'TRUE' : 'FALSE';
        } elseif (is_int($value) || is_float($value)) {
            if (Date::isDateTime($c)) {
                $dt = Date::excelToDateTimeObject($value);
                $s = $dt->format($dt->format('s') === '00' ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s');
            } else {
                $s = rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.');
            }
        } else {
            $s = (string) $value;
        }
        $s = trim($s);

        return $s === '' ? null : $s;
    }
}
