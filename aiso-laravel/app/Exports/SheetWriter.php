<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Writes sheets of one workbook: bold header row, frozen, right-to-left in Persian. */
final class SheetWriter
{
    public readonly Spreadsheet $book;

    public function __construct(Spreadsheet $book, private readonly bool $rtl)
    {
        $this->book = $book;
    }

    /** @param array<int, array<int, mixed>> $rows */
    public function sheet(string $name, array $headers, array $rows): void
    {
        $s = new Worksheet($this->book, mb_substr($name, 0, 31));
        $this->book->addSheet($s);
        if ($this->rtl) {
            $s->setRightToLeft(true);
        }
        foreach ($headers as $i => $h) {
            $cell = $s->getCell(Coordinate::stringFromColumnIndex($i + 1) . 1);
            $cell->setValueExplicit($h, DataType::TYPE_STRING);
            $cell->getStyle()->getFont()->setBold(true);
        }
        $r = 2;
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $v) {
                if (is_int($v) || is_float($v)) {
                    $s->getCell(Coordinate::stringFromColumnIndex($i + 1) . $r)->setValueExplicit($v, DataType::TYPE_NUMERIC);
                } elseif ($v !== null && (string) $v !== '') {
                    $text = mb_substr((string) $v, 0, 32000);
                    $s->getCell(Coordinate::stringFromColumnIndex($i + 1) . $r)->setValueExplicit(self::neutralize($text), DataType::TYPE_STRING);
                }
            }
            $r++;
        }
        foreach ($headers as $i => $h) {
            $s->getColumnDimensionByColumn($i + 1)->setWidth(min(60, max(14, mb_strlen($h) + 6)));
        }
        $s->freezePane('A2');
    }

    /** Prevents spreadsheet formula injection from user-entered text. */
    private static function neutralize(string $s): string
    {
        $c = $s[0] ?? '';

        return in_array($c, ['=', '+', '-', '@'], true) && !is_numeric($s) ? "'" . $s : $s;
    }
}
