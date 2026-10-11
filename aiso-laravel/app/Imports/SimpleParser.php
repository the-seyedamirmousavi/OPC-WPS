<?php

namespace App\Imports;

use App\Support\Jalali;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Parses the compact two-sheet layout used by the first sample file (Persian headers):
 *   sheet "opc":    row no | name | spec | code | quantity | step order | step | predecessor rows | resource | direct hours
 *   sheet "source": row no | resource name | simultaneous resources
 * Predecessors reference the "row no" column of the same sheet ("0" or empty = none, "3,8" = rows 3 and 8). Direct hours
 * are taken as the total duration of the operation (not multiplied by quantity).
 */
final class SimpleParser
{
    private const ROW_NO = 'ردیف';
    private const NAME = 'نام';
    private const SPEC = 'مشخصات';
    private const CODE = 'کد';
    private const QTY = 'تعداد';
    private const STEP = 'مرحله';
    private const PRED = 'پیش نیازی از ردیف';
    private const RESOURCE = 'منابع';
    private const HOURS = 'زمان مستقیم ساعت';
    private const RES_NAME = 'نام منابع';
    private const RES_CAPACITY = 'تعداد منابع همزمان';

    private function __construct()
    {
    }

    public static function matches(Spreadsheet $wb): bool
    {
        return SheetReader::has($wb, 'opc') && SheetReader::has($wb, 'source') && !SheetReader::has($wb, 'Resources');
    }

    public static function parse(Spreadsheet $wb): ParsedData
    {
        $d = new ParsedData();
        $d->format = 'SIMPLE';
        $d->resourceSheet = 'source';
        $d->itemSheet = 'opc';
        $d->opSheet = 'opc';
        $d->predSheet = 'opc';
        $d->predColumn = self::PRED;
        $d->hasPredecessorSheet = true;

        $src = SheetReader::find($wb, 'source');
        $opc = SheetReader::find($wb, 'opc');
        self::requireColumns($src, $d, self::RES_NAME, self::RES_CAPACITY);
        self::requireColumns($opc, $d, self::ROW_NO, self::NAME, self::CODE, self::STEP, self::PRED, self::RESOURCE, self::HOURS);
        if ($d->errors !== []) {
            return $d;
        }

        $resourceIdByName = [];
        foreach ($src->rows() as $n) {
            $name = $src->str($n, self::RES_NAME);
            if ($name === null) {
                $d->error($src->name, $n, $src->columnLabel(self::RES_NAME), 'Resource name is required');
                continue;
            }
            $cap = $src->number($n, self::RES_CAPACITY, $d);
            $capacity = $cap === null ? 1 : (int) round($cap);
            if ($capacity < 1) {
                $d->error($src->name, $n, $src->columnLabel(self::RES_CAPACITY), 'Simultaneous resources must be >= 1');
                $capacity = 1;
            }
            $rowNo = $src->str($n, self::ROW_NO) ?? (string) ($n - 1);
            $id = 'RES-' . self::pad($rowNo);
            if (isset($resourceIdByName[$name])) {
                $d->error($src->name, $n, $src->columnLabel(self::RES_NAME), "Duplicate resource name '{$name}'");
                continue;
            }
            $resourceIdByName[$name] = $id;
            $d->resources[] = ['row' => $n, 'id' => $id, 'name' => $name, 'type' => 'WORK_CENTER', 'userId' => null,
                'capacity' => $capacity, 'status' => 'ACTIVE', 'description' => null];
        }

        $rowNumbers = [];
        $itemIds = [];
        foreach ($opc->rows() as $n) {
            $rowNo = $opc->str($n, self::ROW_NO);
            if ($rowNo === null) {
                $d->error($opc->name, $n, $opc->columnLabel(self::ROW_NO), 'Row number is required');
                continue;
            }
            if (isset($rowNumbers[$rowNo])) {
                $d->error($opc->name, $n, $opc->columnLabel(self::ROW_NO), "Duplicate row number {$rowNo}");
                continue;
            }
            $rowNumbers[$rowNo] = true;
            $opId = 'OP-' . self::pad($rowNo);
            $name = $opc->str($n, self::NAME);
            $spec = $opc->str($n, self::SPEC);
            $code = $opc->str($n, self::CODE);
            $step = $opc->str($n, self::STEP);
            $resourceName = $opc->str($n, self::RESOURCE);
            if ($step === null) {
                $d->error($opc->name, $n, $opc->columnLabel(self::STEP), 'Step (operation name) is required');
            }
            $itemId = null;
            if ($code !== null) {
                $itemId = 'ITM-' . $code;
                if (!isset($itemIds[$itemId])) {
                    $itemIds[$itemId] = true;
                    $qty = $opc->number($n, self::QTY, $d);
                    $itemName = ($name ?? $code) . ($spec === null ? '' : " {$spec}");
                    $d->items[] = ['row' => $n, 'id' => $itemId, 'name' => $itemName, 'quantity' => $qty ?? 1.0,
                        'unit' => null, 'supplyType' => null, 'relatedOperationId' => null, 'description' => null];
                }
            }
            $resourceId = null;
            if ($resourceName === null) {
                $d->error($opc->name, $n, $opc->columnLabel(self::RESOURCE), 'Resource is required');
            } else {
                $resourceId = $resourceIdByName[$resourceName] ?? null;
                if ($resourceId === null) {
                    $d->error($opc->name, $n, $opc->columnLabel(self::RESOURCE),
                        "Resource '{$resourceName}' is not defined in sheet '{$src->name}'");
                }
            }
            $hours = $opc->number($n, self::HOURS, $d);
            if ($hours !== null && $hours < 0) {
                $d->error($opc->name, $n, $opc->columnLabel(self::HOURS), 'Time cannot be negative');
                $hours = 0.0;
            }
            $opName = ($step ?? $opId) . ($name === null ? '' : ' - ' . $name . ($spec === null ? '' : " {$spec}"));
            $d->ops[] = ['row' => $n, 'id' => $opId, 'name' => $opName, 'itemId' => $itemId, 'resourceId' => $resourceId,
                'userId' => null, 'prep' => 0.0, 'transport' => 0.0, 'setup' => 0.0, 'direct' => $hours ?? 0.0,
                'status' => null, 'description' => null];
        }

        // second pass: predecessors refer to row numbers
        foreach ($opc->rows() as $n) {
            $rowNo = $opc->str($n, self::ROW_NO);
            $preds = $opc->str($n, self::PRED);
            if ($rowNo === null || $preds === null) {
                continue;
            }
            foreach (preg_split('/[,،;\s]+/u', $preds) ?: [] as $token) {
                $t = trim(Jalali::latin($token));
                if ($t === '' || $t === '0') {
                    continue;
                }
                if (!isset($rowNumbers[$t])) {
                    $d->error($opc->name, $n, $opc->columnLabel(self::PRED), "Predecessor row {$t} does not exist");
                    continue;
                }
                $d->preds[] = ['row' => $n, 'operationId' => 'OP-' . self::pad($rowNo), 'predecessorId' => 'OP-' . self::pad($t),
                    'type' => 'FINISH_TO_START', 'condition' => null, 'mandatory' => true, 'description' => null];
            }
        }

        return $d;
    }

    private static function requireColumns(?SheetReader $s, ParsedData $d, string ...$columns): void
    {
        if ($s === null) {
            return;
        }
        foreach ($columns as $c) {
            if (!$s->hasColumn($c)) {
                $d->error($s->name, 1, $c, "Required column '{$c}' is missing");
            }
        }
    }

    private static function pad(string $number): string
    {
        $digits = trim(Jalali::latin($number));

        return strlen($digits) >= 3 ? $digits : str_repeat('0', 3 - strlen($digits)) . $digits;
    }
}
