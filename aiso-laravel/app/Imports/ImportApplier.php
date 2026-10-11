<?php

namespace App\Imports;

use App\Enums\OperationStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\AppUser;
use App\Models\Item;
use App\Models\Operation;
use App\Models\Predecessor;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\WorkResource;
use App\Services\AdminService;
use App\Services\AuditService;
use App\Services\DependencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Writes a validated import: upserts by business id, audit trail, then re-evaluates readiness. */
class ImportApplier
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    public function __construct(
        private readonly AuditService $audit,
        private readonly DependencyService $dependencies,
    ) {
    }

    /**
     * @param array &$createdUsers receives {userId, fullName, temporaryPassword} for every new user (shown once)
     * @param array $target        see ImportService::resolveTarget()
     * @param array<string, int>|null $ranks
     * @return array<int, array{entity: string, created: int, updated: int}>
     */
    public function apply(ParsedData $d, AppUser $actor, array &$createdUsers, array $target, ?array $ranks): array
    {
        return DB::transaction(function () use ($d, $actor, &$createdUsers, $target, $ranks) {
            $keys = ['users', 'resources', 'items', 'operations', 'predecessors'];
            $created = array_fill_keys($keys, 0);
            $updated = array_fill_keys($keys, 0);

            if ($target['isNew']) {
                $np = Project::query()->create([
                    'id' => $target['id'],
                    'name' => $target['name'],
                    'due_date' => $target['dueDate'],
                    'priority' => $ranks === null ? 1 : ($ranks[$target['id']] ?? 1),
                    'status' => ProjectStatus::ACTIVE,
                    'created_at' => CarbonImmutable::now(),
                ]);
                $this->audit->record($actor->id, 'PROJECT_CREATED', 'Project', $np->id, null,
                    "{$np->name} (priority {$np->priority})", 'Excel import');
                if ($ranks !== null && $target['others'] !== []) {
                    $before = '';
                    $after = '';
                    foreach ($target['others'] as $other) {
                        $before .= "{$other->id}={$other->priority} ";
                        $fresh = Project::query()->find($other->id) ?? $other;
                        $fresh->priority = $ranks[$other->id];
                        $fresh->save();
                        $after .= "{$other->id}={$fresh->priority} ";
                    }
                    $this->audit->record($actor->id, 'PROJECT_PRIORITIES_CHANGED', 'Project', '*', trim($before),
                        trim($after . "{$np->id}={$np->priority}"), "New project {$np->id} added");
                }
            }

            $s = SystemSetting::current();
            if ($d->settings !== null) {
                if ($d->settings['projectId'] !== null) {
                    $s->project_id = $d->settings['projectId'];
                }
                if ($d->settings['projectName'] !== null) {
                    $s->project_name = $d->settings['projectName'];
                }
                if ($d->settings['ownerId'] !== null) {
                    $s->owner_id = $d->settings['ownerId'];
                }
                if ($d->settings['managerId'] !== null) {
                    $s->manager_id = $d->settings['managerId'];
                }
                if ($d->settings['messengerPlatform'] !== null) {
                    $s->messenger_platform = strtoupper($d->settings['messengerPlatform']);
                }
            }
            $oldVersion = $s->data_version;
            $s->data_version = $d->settings !== null && $d->settings['dataVersion'] !== null
                ? $d->settings['dataVersion'] : self::nextVersion($oldVersion);
            AdminService::saveChanged($s);

            foreach ($d->users as $u) {
                $existing = AppUser::query()->find($u['id']);
                if ($existing === null) {
                    $temp = self::tempPassword();
                    AppUser::query()->create([
                        'id' => $u['id'],
                        'full_name' => $u['name'],
                        'user_role' => Role::from($u['role']),
                        'messenger_id' => $u['messengerId'],
                        'active' => $u['active'],
                        'contact_info' => $u['contact'],
                        'password_hash' => Hash::make($temp),
                        'must_change_password' => true,
                        'created_at' => CarbonImmutable::now(),
                    ]);
                    $createdUsers[] = ['userId' => $u['id'], 'fullName' => $u['name'], 'temporaryPassword' => $temp];
                    $this->audit->record($actor->id, 'USER_CREATED', 'User', $u['id'], null, $u['role'], 'Excel import');
                    $created['users']++;
                } else {
                    $before = $existing->user_role->value . '/' . ($existing->active ? 'true' : 'false');
                    $existing->full_name = $u['name'];
                    $existing->user_role = Role::from($u['role']);
                    // an empty Messenger_ID cell keeps the chat the user connected from the app (see BaleLinkService)
                    if ($u['messengerId'] !== null && trim($u['messengerId']) !== '') {
                        $existing->messenger_id = $u['messengerId'];
                    }
                    $existing->active = $u['active'];
                    $existing->contact_info = $u['contact'];
                    $existing->save();
                    $this->audit->record($actor->id, 'USER_UPDATED', 'User', $u['id'], $before,
                        $u['role'] . '/' . ($u['active'] ? 'true' : 'false'), 'Excel import');
                    $updated['users']++;
                }
            }

            foreach ($d->resources as $r) {
                $res = WorkResource::query()->find($r['id']);
                $isNew = $res === null;
                $before = $isNew ? null : self::describeResource($res);
                if ($isNew) {
                    $res = new WorkResource(['id' => $r['id']]);
                }
                $res->name = $r['name'];
                $res->resource_type = $r['type'];
                $res->responsible_user_id = $r['userId'];
                $res->capacity = $r['capacity'];
                $res->status = $r['status'];
                $res->description = $r['description'];
                $res->save();
                $this->audit->record($actor->id, $isNew ? 'RESOURCE_CREATED' : 'RESOURCE_UPDATED', 'Resource', $r['id'],
                    $before, self::describeResource($res), 'Excel import');
                if ($isNew) {
                    $created['resources']++;
                } else {
                    $updated['resources']++;
                }
            }

            foreach ($d->items as $i) {
                $item = Item::query()->find($i['id']);
                $isNew = $item === null;
                if ($isNew) {
                    $item = new Item(['id' => $i['id']]);
                }
                $item->name = $i['name'];
                $item->quantity = $i['quantity'];
                $item->unit = $i['unit'];
                $item->supply_type = $i['supplyType'];
                $item->related_operation_id = $i['relatedOperationId'];
                $item->description = $i['description'];
                $item->save();
                if ($isNew) {
                    $created['items']++;
                } else {
                    $updated['items']++;
                }
            }

            $now = CarbonImmutable::now();
            foreach ($d->ops as $o) {
                $op = Operation::query()->find($o['id']);
                $isNew = $op === null;
                $before = $isNew ? null : self::describeOperation($op);
                if ($isNew) {
                    $op = new Operation(['id' => $o['id'], 'project_id' => $target['id'], 'created_at' => $now]);
                    $st = $o['status'] === null ? OperationStatus::NOT_READY : OperationStatus::from($o['status']);
                    $op->status = $st;
                    if ($st === OperationStatus::COMPLETED) {
                        $op->completed_at = $now;
                        $op->progress_percent = 100;
                        $op->completion_approved = true;
                    } elseif ($st === OperationStatus::CANCELLED) {
                        $op->cancel_reason = 'Imported as CANCELLED';
                    }
                }
                $op->name = $o['name'];
                $op->item_id = $o['itemId'];
                $op->resource_id = $o['resourceId'];
                $op->responsible_user_id = $o['userId'];
                $op->preparation_time = $o['prep'];
                $op->transport_time = $o['transport'];
                $op->setup_time = $o['setup'];
                $op->direct_time = $o['direct'];
                $op->description = $o['description'];
                $op->updated_at = $now;
                $op->save();
                $this->audit->record($actor->id, $isNew ? 'OPERATION_CREATED' : 'OPERATION_UPDATED', 'Operation', $o['id'],
                    $before, self::describeOperation($op), 'Excel import');
                if ($isNew) {
                    $created['operations']++;
                } else {
                    $updated['operations']++;
                }
            }

            if ($d->hasPredecessorSheet) {
                $ids = [];
                foreach ($d->ops as $o) {
                    $ids[$o['id']] = true;
                }
                foreach ($d->preds as $p) {
                    $ids[$p['operationId']] = true;
                }
                Predecessor::query()->whereIn('operation_id', array_map('strval', array_keys($ids)))->delete();
                foreach ($d->preds as $p) {
                    Predecessor::query()->create([
                        'operation_id' => $p['operationId'],
                        'predecessor_id' => $p['predecessorId'],
                        'dependency_type' => $p['type'],
                        'start_condition' => $p['condition'],
                        'mandatory' => $p['mandatory'],
                        'description' => $p['description'],
                    ]);
                    $created['predecessors']++;
                }
            }

            $this->audit->record($actor->id, 'IMPORT_APPLIED', 'Import', $d->format, $oldVersion, $s->data_version,
                'Created ' . self::mapText($created) . ', updated ' . self::mapText($updated));
            $this->dependencies->evaluateAll($actor->id);

            $out = [];
            foreach ($keys as $k) {
                $out[] = ['entity' => $k, 'created' => $created[$k], 'updated' => $updated[$k]];
            }

            return $out;
        });
    }

    private static function describeResource(WorkResource $r): string
    {
        return "name={$r->name}, capacity={$r->capacity}, status={$r->status}, responsible={$r->responsible_user_id}";
    }

    private static function describeOperation(Operation $o): string
    {
        return "name={$o->name}, resource={$o->resource_id}, item={$o->item_id}, hours={$o->totalHours()}, fixedUser={$o->responsible_user_id}";
    }

    /** @param array<string, int> $m */
    private static function mapText(array $m): string
    {
        $parts = [];
        foreach ($m as $k => $v) {
            $parts[] = "{$k}={$v}";
        }

        return '{' . implode(', ', $parts) . '}';
    }

    /** "3" becomes "4"; a non-numeric version gets ".1" appended (the Java behaviour). */
    private static function nextVersion(?string $old): string
    {
        $base = trim($old ?? '0');

        return ctype_digit($base) ? (string) ((int) $base + 1) : $base . '.1';
    }

    private static function tempPassword(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < 12; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
