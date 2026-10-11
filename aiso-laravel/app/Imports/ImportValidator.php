<?php

namespace App\Imports;

use App\Enums\OperationStatus;
use App\Enums\Role;
use App\Models\AppUser;
use App\Models\Item;
use App\Models\Operation;
use App\Models\Predecessor;
use App\Models\WorkResource;
use App\Scheduling\GraphUtil;

/**
 * Every rule an import is checked against, before anything is written: duplicates, unknown references, roles, dependency
 * cycles, cross-project references and structural changes to operations that have already started.
 */
class ImportValidator
{
    /**
     * @param array{existing: ?object, id: string, name: string, isNew: bool, dueDate: mixed, others: array} $target
     */
    public function validate(ParsedData $d, AppUser $actor, array $target): void
    {
        $dbUsers = AppUser::query()->get()->keyBy('id')->all();
        $dbResources = WorkResource::query()->get()->keyBy('id')->all();
        $dbItems = Item::query()->get()->keyBy('id')->all();
        $dbOps = Operation::query()->get()->keyBy('id')->all();
        $dbPreds = Predecessor::query()->get()->all();

        $this->duplicates($d->resourceSheet, array_map(static fn ($r) => [$r['row'], $r['id']], $d->resources), 'Resource_ID', $d);
        $this->duplicates($d->itemSheet, array_map(static fn ($r) => [$r['row'], $r['id']], $d->items), 'Item_ID', $d);
        $this->duplicates($d->opSheet, array_map(static fn ($r) => [$r['row'], $r['id']], $d->ops), 'Operation_ID', $d);
        $this->duplicates('Users', array_map(static fn ($r) => [$r['row'], $r['id']], $d->users), 'User_ID', $d);

        // operations are globally unique: an id already used by another project cannot be taken over
        foreach ($d->ops as $o) {
            $existing = $dbOps[$o['id']] ?? null;
            if ($existing !== null && $existing->project_id !== $target['id']) {
                $d->error($d->opSheet, $o['row'], 'Operation_ID', "Operation '{$o['id']}' already belongs to project {$existing->project_id}; operation ids must be unique across projects");
            }
        }
        foreach ($d->resources as $rr) {
            $old = $dbResources[$rr['id']] ?? null;
            if ($old !== null && (int) $old->capacity !== $rr['capacity']) {
                $d->warnings[] = "Resource {$rr['id']} is shared by all projects: its capacity changes from {$old->capacity} to {$rr['capacity']}.";
            }
        }

        // users
        $roleOf = [];
        foreach ($dbUsers as $u) {
            $roleOf[$u->id] = $u->user_role;
        }
        foreach ($d->users as $u) {
            $existing = $dbUsers[$u['id']] ?? null;
            if ($u['role'] === Role::OWNER->value || ($existing !== null && $existing->user_role === Role::OWNER)) {
                $d->error('Users', $u['row'], 'Role', 'The OWNER account cannot be created or changed by an import');
                continue;
            }
            if ($u['role'] === Role::MANAGER->value && $actor->user_role !== Role::OWNER) {
                $d->error('Users', $u['row'], 'Role', 'Only the OWNER can import a MANAGER');
                continue;
            }
            if ($existing !== null && $existing->user_role === Role::MANAGER && $actor->user_role !== Role::OWNER) {
                $d->error('Users', $u['row'], 'User_ID', 'Only the OWNER can change the MANAGER account');
                continue;
            }
            $roleOf[$u['id']] = Role::from($u['role']);
        }
        $executors = [];
        foreach ($roleOf as $id => $role) {
            if ($role->isExecutor()) {
                $executors[$id] = true;
            }
        }
        $resourceIds = array_fill_keys(array_keys($dbResources), true);
        foreach ($d->resources as $r) {
            $resourceIds[$r['id']] = true;
        }
        $itemIds = array_fill_keys(array_keys($dbItems), true);
        foreach ($d->items as $i) {
            $itemIds[$i['id']] = true;
        }
        $opIds = array_fill_keys(array_keys($dbOps), true);
        foreach ($d->ops as $o) {
            $opIds[$o['id']] = true;
        }

        foreach ($d->resources as $r) {
            if ($r['userId'] !== null && !isset($executors[$r['userId']])) {
                $d->error($d->resourceSheet, $r['row'], 'Responsible_User_ID', "User '{$r['userId']}' does not exist or is not an executive user");
            }
        }
        foreach ($d->items as $i) {
            if ($i['relatedOperationId'] !== null && !isset($opIds[$i['relatedOperationId']])) {
                $d->error($d->itemSheet, $i['row'], 'Related_Operation_ID', "Operation '{$i['relatedOperationId']}' does not exist");
            }
        }
        foreach ($d->ops as $o) {
            if ($o['resourceId'] !== null && !isset($resourceIds[$o['resourceId']])) {
                $d->error($d->opSheet, $o['row'], 'Resource_ID', "Resource '{$o['resourceId']}' does not exist");
            }
            if ($o['itemId'] !== null && !isset($itemIds[$o['itemId']])) {
                $d->error($d->opSheet, $o['row'], 'Item_ID', "Item '{$o['itemId']}' does not exist in the BOM");
            }
            if ($o['userId'] !== null && !isset($executors[$o['userId']])) {
                $d->error($d->opSheet, $o['row'], 'Responsible_User_ID', "User '{$o['userId']}' does not exist or is not an executive user");
            }
        }

        // predecessors
        $seenPairs = [];
        $fileOpIds = [];
        foreach ($d->ops as $o) {
            $fileOpIds[$o['id']] = true;
        }
        foreach ($d->preds as $p) {
            $dbOp = $dbOps[$p['operationId']] ?? null;
            $dbPred = $dbOps[$p['predecessorId']] ?? null;
            if ($dbPred !== null && $dbPred->project_id !== $target['id']) {
                $d->error($d->predSheet, $p['row'], $d->predColumn, "Predecessor '{$p['predecessorId']}' belongs to project {$dbPred->project_id}; dependencies cannot cross projects");
            }
            if ($dbOp !== null && $dbOp->project_id !== $target['id']) {
                $d->error($d->predSheet, $p['row'], 'Operation_ID', "Operation '{$p['operationId']}' belongs to project {$dbOp->project_id}");
            }
            if (!isset($opIds[$p['operationId']])) {
                $d->error($d->predSheet, $p['row'], 'Operation_ID', "Operation '{$p['operationId']}' does not exist");
            }
            if (!isset($opIds[$p['predecessorId']])) {
                $d->error($d->predSheet, $p['row'], $d->predColumn, "Predecessor '{$p['predecessorId']}' does not exist");
            }
            if ($p['operationId'] === $p['predecessorId']) {
                $d->error($d->predSheet, $p['row'], $d->predColumn, 'An operation cannot be its own predecessor');
            }
            $pair = $p['operationId'] . '>' . $p['predecessorId'];
            if (isset($seenPairs[$pair])) {
                $d->error($d->predSheet, $p['row'], $d->predColumn, "Duplicate predecessor {$p['predecessorId']} for {$p['operationId']}");
            }
            $seenPairs[$pair] = true;
            if ($d->hasPredecessorSheet && !isset($fileOpIds[$p['operationId']]) && isset($opIds[$p['operationId']])) {
                $d->warnings[] = "Predecessors row {$p['row']}: operation {$p['operationId']} is not in the OPC sheet of this file; its predecessors are replaced anyway.";
            }
        }

        // settings
        if ($d->settings !== null) {
            if ($d->settings['ownerId'] !== null && !isset($roleOf[$d->settings['ownerId']])) {
                $d->error('Settings', $d->settings['row'], 'Owner_ID', "User '{$d->settings['ownerId']}' does not exist");
            }
            if ($d->settings['managerId'] !== null && !isset($roleOf[$d->settings['managerId']])) {
                $d->error('Settings', $d->settings['row'], 'Manager_ID', "User '{$d->settings['managerId']}' does not exist");
            }
        }

        // structural changes to operations that have already started
        $dbPredSets = [];
        foreach ($dbPreds as $p) {
            $dbPredSets[$p->operation_id][$p->predecessor_id . ':' . $p->dependency_type->value . ':' . self::bool($p->mandatory)] = true;
        }
        $filePredSets = [];
        foreach ($d->preds as $p) {
            $filePredSets[$p['operationId']][$p['predecessorId'] . ':' . $p['type'] . ':' . self::bool($p['mandatory'])] = true;
        }
        foreach ($d->ops as $o) {
            $existing = $dbOps[$o['id']] ?? null;
            if ($existing === null || $existing->status === OperationStatus::NOT_READY || $existing->status === OperationStatus::READY) {
                continue;
            }
            if ($o['resourceId'] !== null && $o['resourceId'] !== $existing->resource_id) {
                $d->error($d->opSheet, $o['row'], 'Resource_ID', "Operation {$o['id']} is {$existing->status->value}; its resource cannot be changed");
            }
            if ($d->hasPredecessorSheet && ($dbPredSets[$o['id']] ?? []) !== ($filePredSets[$o['id']] ?? [])) {
                $d->error($d->opSheet, $o['row'], 'Operation_ID', "Operation {$o['id']} is {$existing->status->value}; its predecessors cannot be changed");
            }
        }

        // cycles in the merged graph
        $merged = [];
        foreach (array_keys($opIds) as $id) {
            $merged[(string) $id] = [];
        }
        foreach ($dbPreds as $p) {
            if (!$d->hasPredecessorSheet || !isset($fileOpIds[$p->operation_id])) {
                $merged[$p->operation_id][] = $p->predecessor_id;
            }
        }
        foreach ($d->preds as $p) {
            if (isset($opIds[$p['operationId']], $opIds[$p['predecessorId']]) && $p['operationId'] !== $p['predecessorId']) {
                $merged[$p['operationId']][] = $p['predecessorId'];
            }
        }
        $cycle = GraphUtil::findCycle($merged);
        if ($cycle !== []) {
            $row = 1;
            foreach ($d->preds as $p) {
                if ($p['operationId'] === $cycle[0] && $p['predecessorId'] === ($cycle[1] ?? null)) {
                    $row = $p['row'];
                    break;
                }
            }
            $d->error($d->predSheet, $row, $d->predColumn, 'Dependency cycle: ' . implode(' -> ', $cycle)
                . '. A real loop in the process must be resolved by the manager, it is not removed automatically.');
        }

        $missing = 0;
        foreach ($dbOps as $o) {
            if ($o->project_id === $target['id'] && !isset($fileOpIds[$o->id])) {
                $missing++;
            }
        }
        if ($missing > 0 && $d->ops !== []) {
            $d->warnings[] = "{$missing} existing operation(s) are not in this file and were left unchanged.";
        }
    }

    /** @param array<int, array{0: int, 1: string}> $rows [row, id] */
    private function duplicates(string $sheet, array $rows, string $column, ParsedData $d): void
    {
        $first = [];
        foreach ($rows as [$row, $id]) {
            if (isset($first[$id])) {
                $d->error($sheet, $row, $column, "Duplicate id '{$id}' (first used in row {$first[$id]})");
            } else {
                $first[$id] = $row;
            }
        }
    }

    private static function bool(bool $b): string
    {
        return $b ? 'true' : 'false';
    }
}
