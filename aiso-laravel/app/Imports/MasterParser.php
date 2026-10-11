<?php

namespace App\Imports;

use App\Enums\OperationStatus;
use App\Enums\Role;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** Parses the master template (sheets Resources, BOM, OPC, Predecessors, Users, Settings). */
final class MasterParser
{
    private const INITIAL_STATUSES = ['NOT_READY', 'READY', 'COMPLETED', 'CANCELLED'];

    private function __construct()
    {
    }

    public static function parse(Spreadsheet $wb): ParsedData
    {
        $d = new ParsedData();
        $d->format = 'MASTER';
        self::resources($wb, $d);
        self::bom($wb, $d);
        self::opc($wb, $d);
        self::predecessors($wb, $d);
        self::users($wb, $d);
        self::settings($wb, $d);

        return $d;
    }

    /** @param string[] $mustHave */
    private static function sheet(Spreadsheet $wb, ParsedData $d, string $name, bool $required, array $mustHave): ?SheetReader
    {
        $s = SheetReader::find($wb, $name);
        if ($s === null) {
            if ($required) {
                $d->error($name, 1, '-', "Required sheet '{$name}' is missing");
            } else {
                $d->warnings[] = "Sheet '{$name}' is not in the file; existing {$name} data is left unchanged.";
            }

            return null;
        }
        $ok = true;
        foreach ($mustHave as $col) {
            if (!$s->hasColumn($col)) {
                $d->error($s->name, 1, $col, "Required column '{$col}' is missing");
                $ok = false;
            }
        }

        return $ok ? $s : null;
    }

    private static function resources(Spreadsheet $wb, ParsedData $d): void
    {
        $s = self::sheet($wb, $d, 'Resources', true, ['Resource_ID', 'Resource_Name']);
        if ($s === null) {
            return;
        }
        foreach ($s->rows() as $n) {
            $id = $s->str($n, 'Resource_ID');
            $name = $s->str($n, 'Resource_Name');
            if ($id === null) {
                $d->error($s->name, $n, 'Resource_ID', 'Resource_ID is required');
                continue;
            }
            if ($name === null) {
                $d->error($s->name, $n, 'Resource_Name', 'Resource_Name is required');
            }
            $cap = $s->number($n, 'Capacity', $d);
            $capacity = $cap === null ? 1 : (int) round($cap);
            if ($cap !== null && ($cap < 1 || $cap != round($cap))) {
                $d->error($s->name, $n, 'Capacity', 'Capacity must be a whole number >= 1');
                $capacity = 1;
            }
            $status = self::upper($s->str($n, 'Status'), 'ACTIVE');
            if ($status !== 'ACTIVE' && $status !== 'INACTIVE') {
                $d->error($s->name, $n, 'Status', 'Status must be ACTIVE or INACTIVE');
                $status = 'ACTIVE';
            }
            $d->resources[] = ['row' => $n, 'id' => $id, 'name' => $name ?? $id, 'type' => $s->str($n, 'Resource_Type'),
                'userId' => $s->str($n, 'Responsible_User_ID'), 'capacity' => $capacity, 'status' => $status,
                'description' => $s->str($n, 'Description')];
        }
    }

    private static function bom(Spreadsheet $wb, ParsedData $d): void
    {
        $s = self::sheet($wb, $d, 'BOM', false, ['Item_ID', 'Item_Name']);
        if ($s === null) {
            return;
        }
        foreach ($s->rows() as $n) {
            $id = $s->str($n, 'Item_ID');
            $name = $s->str($n, 'Item_Name');
            if ($id === null) {
                $d->error($s->name, $n, 'Item_ID', 'Item_ID is required');
                continue;
            }
            if ($name === null) {
                $d->error($s->name, $n, 'Item_Name', 'Item_Name is required');
            }
            $qty = $s->number($n, 'Quantity', $d);
            if ($qty !== null && $qty < 0) {
                $d->error($s->name, $n, 'Quantity', 'Quantity cannot be negative');
            }
            $d->items[] = ['row' => $n, 'id' => $id, 'name' => $name ?? $id, 'quantity' => $qty === null ? 1.0 : max(0.0, $qty),
                'unit' => $s->str($n, 'Unit'), 'supplyType' => $s->str($n, 'Supply_Type'),
                'relatedOperationId' => $s->str($n, 'Related_Operation_ID'), 'description' => $s->str($n, 'Description')];
        }
    }

    private static function opc(Spreadsheet $wb, ParsedData $d): void
    {
        $s = self::sheet($wb, $d, 'OPC', true, ['Operation_ID', 'Operation_Name', 'Resource_ID']);
        if ($s === null) {
            return;
        }
        foreach ($s->rows() as $n) {
            $id = $s->str($n, 'Operation_ID');
            $name = $s->str($n, 'Operation_Name');
            $resource = $s->str($n, 'Resource_ID');
            if ($id === null) {
                $d->error($s->name, $n, 'Operation_ID', 'Operation_ID is required');
                continue;
            }
            if ($name === null) {
                $d->error($s->name, $n, 'Operation_Name', 'Operation_Name is required');
            }
            if ($resource === null) {
                $d->error($s->name, $n, 'Resource_ID', 'Resource_ID is required');
            }
            $prep = self::time($s, $n, 'Preparation_Time', $d);
            $transport = self::time($s, $n, 'Transport_Time', $d);
            $setup = self::time($s, $n, 'Setup_Time', $d);
            $direct = self::time($s, $n, 'Direct_Time', $d);
            $status = null;
            $st = self::upper($s->str($n, 'Operation_Status'), null);
            if ($st !== null) {
                if (in_array($st, self::INITIAL_STATUSES, true) && OperationStatus::tryFrom($st) !== null) {
                    $status = $st;
                } elseif (OperationStatus::tryFrom($st) === null) {
                    $d->error($s->name, $n, 'Operation_Status', "Unknown status '{$st}'");
                } else {
                    $d->error($s->name, $n, 'Operation_Status',
                        "Initial status must be empty, NOT_READY, READY, COMPLETED or CANCELLED (got {$st})");
                }
            }
            $d->ops[] = ['row' => $n, 'id' => $id, 'name' => $name ?? $id, 'itemId' => $s->str($n, 'Item_ID'),
                'resourceId' => $resource, 'userId' => $s->str($n, 'Responsible_User_ID'), 'prep' => $prep,
                'transport' => $transport, 'setup' => $setup, 'direct' => $direct, 'status' => $status,
                'description' => $s->str($n, 'Description')];
        }
    }

    private static function time(SheetReader $s, int $row, string $column, ParsedData $d): float
    {
        $v = $s->number($row, $column, $d);
        if ($v === null) {
            return 0.0;
        }
        if ($v < 0) {
            $d->error($s->name, $row, $column, 'Time cannot be negative');

            return 0.0;
        }

        return $v;
    }

    private static function predecessors(Spreadsheet $wb, ParsedData $d): void
    {
        $s = self::sheet($wb, $d, 'Predecessors', false, ['Operation_ID', 'Predecessor_Operation_ID']);
        if ($s === null) {
            return;
        }
        $d->hasPredecessorSheet = true;
        foreach ($s->rows() as $n) {
            $op = $s->str($n, 'Operation_ID');
            $pred = $s->str($n, 'Predecessor_Operation_ID');
            if ($op === null || $pred === null) {
                $d->error($s->name, $n, $op === null ? 'Operation_ID' : 'Predecessor_Operation_ID', 'Both ids are required');
                continue;
            }
            $type = 'FINISH_TO_START';
            $t = self::upper($s->str($n, 'Dependency_Type'), null);
            if ($t !== null) {
                $normalized = match ($t) {
                    'FS', 'FINISH_TO_START', 'FINISHTOSTART' => 'FINISH_TO_START',
                    'SS', 'START_TO_START', 'STARTTOSTART' => 'START_TO_START',
                    default => null,
                };
                if ($normalized === null) {
                    $d->error($s->name, $n, 'Dependency_Type', "Dependency_Type must be FINISH_TO_START or START_TO_START (got {$t})");
                } else {
                    $type = $normalized;
                }
            }
            $mandatory = true;
            $m = self::upper($s->str($n, 'Is_Mandatory'), null);
            if ($m !== null) {
                $b = self::bool($m);
                if ($b === null) {
                    $d->error($s->name, $n, 'Is_Mandatory', 'Is_Mandatory must be TRUE or FALSE');
                } else {
                    $mandatory = $b;
                }
            }
            $d->preds[] = ['row' => $n, 'operationId' => $op, 'predecessorId' => $pred, 'type' => $type,
                'condition' => $s->str($n, 'Start_Condition'), 'mandatory' => $mandatory, 'description' => $s->str($n, 'Description')];
        }
    }

    private static function users(Spreadsheet $wb, ParsedData $d): void
    {
        $s = self::sheet($wb, $d, 'Users', false, ['User_ID', 'Full_Name', 'Role']);
        if ($s === null) {
            return;
        }
        foreach ($s->rows() as $n) {
            $id = $s->str($n, 'User_ID');
            $name = $s->str($n, 'Full_Name');
            $roleText = self::upper($s->str($n, 'Role'), null);
            if ($id === null) {
                $d->error($s->name, $n, 'User_ID', 'User_ID is required');
                continue;
            }
            if ($name === null) {
                $d->error($s->name, $n, 'Full_Name', 'Full_Name is required');
            }
            $role = null;
            if ($roleText === null) {
                $d->error($s->name, $n, 'Role', 'Role is required');
            } elseif (Role::tryFrom($roleText) === null) {
                $d->error($s->name, $n, 'Role', 'Role must be one of OWNER, MANAGER, USER_1, USER_2, USER_3');
            } else {
                $role = $roleText;
            }
            $active = true;
            $a = self::upper($s->str($n, 'Active_Status'), null);
            if ($a !== null) {
                $b = self::bool($a);
                if ($b === null) {
                    $d->error($s->name, $n, 'Active_Status', 'Active_Status must be TRUE/FALSE or ACTIVE/INACTIVE');
                } else {
                    $active = $b;
                }
            }
            if ($role !== null) {
                $d->users[] = ['row' => $n, 'id' => $id, 'name' => $name ?? $id, 'role' => $role,
                    'messengerId' => $s->str($n, 'Messenger_ID'), 'active' => $active, 'contact' => $s->str($n, 'Contact_Info')];
            }
        }
    }

    private static function settings(Spreadsheet $wb, ParsedData $d): void
    {
        $s = self::sheet($wb, $d, 'Settings', false, []);
        if ($s === null) {
            return;
        }
        $rows = $s->rows();
        if ($rows === []) {
            return;
        }
        if (count($rows) > 1) {
            $d->error($s->name, $rows[1], '-', 'The Settings sheet must contain exactly one data row');
        }
        $r = $rows[0];
        $d->settings = ['row' => $r, 'projectId' => $s->str($r, 'Project_ID'), 'projectName' => $s->str($r, 'Project_Name'),
            'ownerId' => $s->str($r, 'Owner_ID'), 'managerId' => $s->str($r, 'Manager_ID'),
            'messengerPlatform' => $s->str($r, 'Messenger_Platform'), 'dataVersion' => $s->str($r, 'Data_Version')];
    }

    private static function upper(?string $s, ?string $fallback): ?string
    {
        if ($s === null) {
            return $fallback;
        }

        return strtoupper(str_replace(' ', '_', trim(FaNames::value(trim($s)) ?? '')));
    }

    private static function bool(string $upper): ?bool
    {
        return match ($upper) {
            'TRUE', 'YES', 'Y', '1', 'ACTIVE' => true,
            'FALSE', 'NO', 'N', '0', 'INACTIVE' => false,
            default => null,
        };
    }
}
