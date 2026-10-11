<?php

namespace App\Imports;

/**
 * Typed content of a workbook, independent of the source layout (master template or simple format). Rows are plain
 * arrays with the keys documented next to each list.
 */
final class ParsedData
{
    public string $format = '';
    /** Sheet names used in error messages (they differ between the master template and the simple layout). */
    public string $resourceSheet = 'Resources';
    public string $itemSheet = 'BOM';
    public string $opSheet = 'OPC';
    public string $predSheet = 'Predecessors';
    public string $predColumn = 'Predecessor_Operation_ID';

    /** @var array<int, array{row: int, id: string, name: string, type: ?string, userId: ?string, capacity: int, status: string, description: ?string}> */
    public array $resources = [];
    /** @var array<int, array{row: int, id: string, name: string, quantity: float, unit: ?string, supplyType: ?string, relatedOperationId: ?string, description: ?string}> */
    public array $items = [];
    /** @var array<int, array{row: int, id: string, name: string, itemId: ?string, resourceId: ?string, userId: ?string, prep: float, transport: float, setup: float, direct: float, status: ?string, description: ?string}> */
    public array $ops = [];
    /** @var array<int, array{row: int, operationId: string, predecessorId: string, type: string, condition: ?string, mandatory: bool, description: ?string}> */
    public array $preds = [];
    /** @var array<int, array{row: int, id: string, name: string, role: string, messengerId: ?string, active: bool, contact: ?string}> */
    public array $users = [];
    /** @var array{row: int, projectId: ?string, projectName: ?string, ownerId: ?string, managerId: ?string, messengerPlatform: ?string, dataVersion: ?string}|null */
    public ?array $settings = null;

    /** False when the file has no Predecessors sheet, in which case existing predecessors are left untouched. */
    public bool $hasPredecessorSheet = false;

    /** @var array<int, array{sheet: string, row: int, column: ?string, message: string}> */
    public array $errors = [];
    /** @var string[] */
    public array $warnings = [];

    public function error(string $sheet, int $row, ?string $column, string $message): void
    {
        $this->errors[] = ['sheet' => $sheet, 'row' => $row, 'column' => $column, 'message' => $message];
    }
}
