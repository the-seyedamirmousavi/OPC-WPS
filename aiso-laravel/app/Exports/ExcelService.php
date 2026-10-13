<?php

namespace App\Exports;

use App\Imports\FaNames;
use App\Enums\ProjectStatus;
use App\Models\AppUser;
use App\Models\AuditEvent;
use App\Models\ImportLog;
use App\Models\Item;
use App\Models\Operation;
use App\Models\Predecessor;
use App\Models\Project;
use App\Models\WorkResource;
use App\Services\DashboardService;
use App\Services\OperationViewService;
use App\Services\PlanService;
use App\Services\ProjectDataService;
use App\Services\SchedulingService;
use App\Support\Jalali;
use App\Support\Text\FaLabels;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Produces the downloadable Excel files: the standard import template and the status report. Both exist in English and in
 * Persian. The Persian files use Persian sheet names and headers, Persian values for statuses, Solar Hijri dates in
 * Tehran time and right-to-left sheets. The importer accepts the Persian names, so a Persian template can be filled in
 * and uploaded as it is.
 */
class ExcelService
{
    public function __construct(
        private readonly OperationViewService $operations,
        private readonly DashboardService $dashboards,
        private readonly SchedulingService $scheduling,
        private readonly ProjectDataService $dataService,
        private readonly PlanService $planService,
    ) {
    }

    // ---- template -----------------------------------------------------------------------------------------------

    /** The standard master template with one example row per sheet. */
    public function template(bool $fa): string
    {
        $o = new SheetWriter(new Spreadsheet(), $fa);
        if ($fa) {
            $o->sheet('منابع', self::h('Resource_ID', 'Resource_Name', 'Resource_Type', 'Responsible_User_ID', 'Capacity', 'Status', 'Description'),
                [['R-WELD', 'جوشکاری', 'مرکز کاری', 'user1', 2, 'فعال', 'دو ایستگاه جوش'],
                    ['R-PAINT', 'رنگ‌کاری', 'مرکز کاری', '', 1, 'فعال', '']]);
            $o->sheet('قطعات', self::h('Item_ID', 'Item_Name', 'Quantity', 'Unit', 'Supply_Type', 'Related_Operation_ID', 'Description'),
                [['ITM-101', 'باکس سنگین', 2, 'عدد', 'ساخت', 'OP-001', '']]);
            $o->sheet('عملیات', self::h('Operation_ID', 'Operation_Name', 'Item_ID', 'Resource_ID', 'Responsible_User_ID',
                'Preparation_Time', 'Transport_Time', 'Setup_Time', 'Direct_Time', 'Operation_Status', 'Description'),
                [['OP-001', 'جوشکاری باکس', 'ITM-101', 'R-WELD', '', 0, 0, 1, 16, '', ''],
                    ['OP-002', 'رنگ‌کاری باکس', 'ITM-101', 'R-PAINT', '', 0, 1, 0.5, 6, '', '']]);
            $o->sheet('پیش‌نیازها', self::h('Operation_ID', 'Predecessor_Operation_ID', 'Dependency_Type', 'Start_Condition', 'Is_Mandatory', 'Description'),
                [['OP-002', 'OP-001', 'پایان به شروع', '', 'بله', '']]);
            $o->sheet('کاربران', self::h('User_ID', 'Full_Name', 'Role', 'Messenger_ID', 'Active_Status', 'Contact_Info'),
                [['USER_1', 'کاربر اجرایی ۱', 'کاربر ۱', '', 'فعال', ''],
                    ['USER_2', 'کاربر اجرایی ۲', 'کاربر ۲', '', 'فعال', ''],
                    ['USER_3', 'کاربر اجرایی ۳', 'کاربر ۳', '', 'فعال', '']]);
            $o->sheet('تنظیمات', self::h('Project_ID', 'Project_Name', 'Owner_ID', 'Manager_ID', 'Messenger_Platform', 'System_Status',
                'Data_Version', 'Configuration_Version'),
                [['P-001', 'پروژهٔ نمونه', 'owner', 'manager', 'IN_APP', 'ACTIVE', '1', '1']]);
        } else {
            $o->sheet('Resources', ['Resource_ID', 'Resource_Name', 'Resource_Type', 'Responsible_User_ID', 'Capacity', 'Status', 'Description'],
                [['R-WELD', 'Welding', 'WORK_CENTER', 'USER_1', 2, 'ACTIVE', 'Two welding stations'],
                    ['R-PAINT', 'Painting', 'WORK_CENTER', '', 1, 'ACTIVE', '']]);
            $o->sheet('BOM', ['Item_ID', 'Item_Name', 'Quantity', 'Unit', 'Supply_Type', 'Related_Operation_ID', 'Description'],
                [['ITM-101', 'Box HD', 2, 'pcs', 'MAKE', 'OP-001', '']]);
            $o->sheet('OPC', ['Operation_ID', 'Operation_Name', 'Item_ID', 'Resource_ID', 'Responsible_User_ID',
                'Preparation_Time', 'Transport_Time', 'Setup_Time', 'Direct_Time', 'Operation_Status', 'Description'],
                [['OP-001', 'Weld box', 'ITM-101', 'R-WELD', '', 0, 0, 1, 16, '', ''],
                    ['OP-002', 'Paint box', 'ITM-101', 'R-PAINT', '', 0, 1, 0.5, 6, '', '']]);
            $o->sheet('Predecessors', ['Operation_ID', 'Predecessor_Operation_ID', 'Dependency_Type', 'Start_Condition', 'Is_Mandatory', 'Description'],
                [['OP-002', 'OP-001', 'FINISH_TO_START', '', 'TRUE', '']]);
            $o->sheet('Users', ['User_ID', 'Full_Name', 'Role', 'Messenger_ID', 'Active_Status', 'Contact_Info'],
                [['USER_1', 'Executive User 1', 'USER_1', '', 'TRUE', ''],
                    ['USER_2', 'Executive User 2', 'USER_2', '', 'TRUE', ''],
                    ['USER_3', 'Executive User 3', 'USER_3', '', 'TRUE', '']]);
            $o->sheet('Settings', ['Project_ID', 'Project_Name', 'Owner_ID', 'Manager_ID', 'Messenger_Platform', 'System_Status',
                'Data_Version', 'Configuration_Version'],
                [['P-001', 'Sample project', 'owner', 'manager', 'IN_APP', 'ACTIVE', '1', '1']]);
        }

        return self::write($o->book);
    }

    // ---- the plan as an importable workbook -----------------------------------------------------------------------

    /**
     * The current plan of one project in the master template layout, so it can be edited in Excel and imported again, plus
     * a Schedule sheet with the projected start and end of every operation. Users are limited to executive users (an
     * import cannot create managers or owners); Settings only names the project.
     */
    public function plan(bool $fa, ?string $projectId): string
    {
        $project = $projectId !== null && $projectId !== ''
            ? Project::query()->find($projectId)
            : Project::query()->where('status', ProjectStatus::ACTIVE->value)->orderBy('priority')->orderBy('id')->first();
        $ops = $project === null ? collect() : Operation::query()->where('project_id', $project->id)->orderBy('id')->get();
        $opIds = $ops->pluck('id')->all();
        $itemIds = $ops->pluck('item_id')->filter()->unique()->values()->all();

        $resources = [];
        foreach (WorkResource::inEnteredOrder()->get() as $r) {
            $resources[] = [$r->id, $r->name, $r->resource_type ?? '', $r->responsible_user_id ?? '', (int) $r->capacity, $r->status, $r->description ?? ''];
        }
        $items = [];
        foreach (Item::query()->whereIn('id', $itemIds)->orderBy('id')->get() as $i) {
            $items[] = [$i->id, $i->name, (float) $i->quantity, $i->unit ?? '', $i->supply_type ?? '', $i->related_operation_id ?? '', $i->description ?? ''];
        }
        $opRows = [];
        foreach ($ops as $o) {
            $opRows[] = [$o->id, $o->name, $o->item_id ?? '', $o->resource_id, $o->responsible_user_id ?? '',
                (float) $o->preparation_time, (float) $o->transport_time, (float) $o->setup_time, (float) $o->direct_time, '', $o->description ?? ''];
        }
        $preds = [];
        if ($opIds !== []) {
            foreach (Predecessor::query()->whereIn('operation_id', $opIds)->orderBy('operation_id')->orderBy('predecessor_id')->get() as $p) {
                $preds[] = [$p->operation_id, $p->predecessor_id, $p->dependency_type->value, $p->start_condition ?? '', $p->mandatory ? 'TRUE' : 'FALSE', $p->description ?? ''];
            }
        }
        $users = [];
        foreach (AppUser::query()->orderBy('id')->get() as $u) {
            if ($u->receivesTasks()) {
                $users[] = [$u->id, $u->full_name, $u->user_role, '', $u->active ? 'TRUE' : 'FALSE', $u->contact_info ?? ''];
            }
        }
        $settings = [[$project?->id ?? '', $project?->name ?? '', '', '', '', '', '', '']];

        $schedule = [];
        $byId = array_flip($opIds);
        foreach ($this->operations->all() as $v) {
            if (!isset($byId[$v['id']])) {
                continue;
            }
            $schedule[] = [$v['id'], $v['name'], $v['resourceName'], self::round($v['totalHours']),
                $fa ? FaLabels::status($v['status']) : $v['status'],
                self::when($fa, $v['projectedStart'] ?? null, true), self::when($fa, $v['projectedEnd'] ?? null, true)];
        }

        $o = new SheetWriter(new Spreadsheet(), $fa);
        $names = $fa
            ? ['منابع', 'قطعات', 'عملیات', 'پیش‌نیازها', 'کاربران', 'تنظیمات', 'برنامه زمان‌بندی']
            : ['Resources', 'BOM', 'OPC', 'Predecessors', 'Users', 'Settings', 'Schedule'];
        $o->sheet($names[0], self::hdr($fa, 'Resource_ID', 'Resource_Name', 'Resource_Type', 'Responsible_User_ID', 'Capacity', 'Status', 'Description'), $resources);
        $o->sheet($names[1], self::hdr($fa, 'Item_ID', 'Item_Name', 'Quantity', 'Unit', 'Supply_Type', 'Related_Operation_ID', 'Description'), $items);
        $o->sheet($names[2], self::hdr($fa, 'Operation_ID', 'Operation_Name', 'Item_ID', 'Resource_ID', 'Responsible_User_ID',
            'Preparation_Time', 'Transport_Time', 'Setup_Time', 'Direct_Time', 'Operation_Status', 'Description'), $opRows);
        $o->sheet($names[3], self::hdr($fa, 'Operation_ID', 'Predecessor_Operation_ID', 'Dependency_Type', 'Start_Condition', 'Is_Mandatory', 'Description'), $preds);
        $o->sheet($names[4], self::hdr($fa, 'User_ID', 'Full_Name', 'Role', 'Messenger_ID', 'Active_Status', 'Contact_Info'), $users);
        $o->sheet($names[5], self::hdr($fa, 'Project_ID', 'Project_Name', 'Owner_ID', 'Manager_ID', 'Messenger_Platform', 'System_Status', 'Data_Version', 'Configuration_Version'), $settings);
        $o->sheet($names[6],
            $fa ? ['شناسه عملیات', 'نام', 'منبع', 'مدت (ساعت)', 'وضعیت', 'شروع پیش‌بینی (شمسی)', 'پایان پیش‌بینی (شمسی)']
                : ['Operation_ID', 'Name', 'Resource', 'Total_Hours', 'Status', 'Projected_Start', 'Projected_End'],
            $schedule);

        return self::write($o->book);
    }

    /** Headers of the master layout: canonical names in English, Persian names in the Persian workbook. */
    private static function hdr(bool $fa, string ...$canonical): array
    {
        return $fa ? self::h(...$canonical) : $canonical;
    }

    /** @return string[] Persian headers for the canonical column names. */
    private static function h(string ...$canonical): array
    {
        return array_map(static fn (string $c) => FaNames::header($c), $canonical);
    }

    // ---- report -------------------------------------------------------------------------------------------------

    /** Projects, operations, user performance, import history (with errors) and the recent audit trail. */
    public function report(bool $fa): string
    {
        $o = new SheetWriter(new Spreadsheet(), $fa);
        $data = $this->dataService->load();
        $projects = [];
        foreach ($this->scheduling->summaries($data, $this->planService->plan($data)) as $p) {
            $projects[] = [
                $p['id'], $p['name'], $p['priority'],
                $fa ? FaLabels::projectStatus($p['status']) : $p['status'],
                self::when($fa, $p['dueDate'], false),
                $p['totalOperations'], self::round($p['progressPercent']),
                self::when($fa, $p['finishAt'], true), self::round($p['tardinessHours']),
            ];
        }
        $o->sheet($fa ? 'پروژه‌ها' : 'Projects',
            $fa ? ['شناسه پروژه', 'نام', 'اولویت', 'وضعیت', 'سررسید (شمسی)', 'تعداد عملیات', 'پیشرفت ٪', 'پایان پیش‌بینی (شمسی)', 'تأخیر (ساعت)']
                : ['Project_ID', 'Name', 'Priority', 'Status', 'Due_Date', 'Operations', 'Progress_%', 'Projected_Finish', 'Tardiness_Hours'],
            $projects);

        $ops = [];
        foreach ($this->operations->all() as $v) {
            $ops[] = [
                $v['id'], $v['name'], $v['projectName'] ?? '', $v['resourceName'],
                $fa ? FaLabels::status($v['status']) : $v['status'],
                $v['assignedUserName'] ?? '', self::round($v['totalHours']), $v['progressPercent'],
                self::when($fa, $v['plannedEnd'], true), self::when($fa, $v['projectedEnd'], true),
                $fa ? FaLabels::yesNo($v['delayed']) : ($v['delayed'] ? 'YES' : 'NO'),
                self::round($v['delayHours']), $v['blockReason'] ?? '',
            ];
        }
        $o->sheet($fa ? 'عملیات' : 'Operations',
            $fa ? ['شناسه عملیات', 'نام', 'پروژه', 'منبع', 'وضعیت', 'تخصیص به', 'مدت (ساعت)', 'پیشرفت ٪', 'مهلت (شمسی)', 'پایان پیش‌بینی (شمسی)', 'تأخیر', 'ساعت تأخیر', 'علت انسداد']
                : ['Operation_ID', 'Name', 'Project', 'Resource', 'Status', 'Assigned_To', 'Total_Hours', 'Progress_%', 'Planned_End', 'Projected_End', 'Delayed', 'Delay_Hours', 'Block_Reason'],
            $ops);

        $perf = [];
        foreach ($this->dashboards->performanceAll() as $p) {
            $perf[] = [
                $p['userId'], $p['name'] ?? '', $p['assigned'], $p['inProgress'], $p['blocked'], $p['completed'],
                self::round($p['completedHours']),
                $p['onTimeRate'] === null ? '' : self::round($p['onTimeRate'] * 100) . '%',
                $p['actualToPlanned'] === null ? '' : self::round($p['actualToPlanned']),
                $p['lateCount'], $p['blockReports'],
            ];
        }
        $o->sheet($fa ? 'عملکرد کاربران' : 'User_Performance',
            $fa ? ['شناسه کاربر', 'نام', 'تخصیص‌یافته', 'در حال انجام', 'مسدود', 'تکمیل‌شده', 'ساعت تکمیل‌شده', 'به‌موقع', 'واقعی ÷ برنامه', 'با تأخیر', 'مشکلات گزارش‌شده']
                : ['User_ID', 'Name', 'Assigned', 'In_Progress', 'Blocked', 'Completed', 'Completed_Hours', 'On_Time_Rate', 'Actual_vs_Planned', 'Late', 'Block_Reports'],
            $perf);

        $imp = [];
        foreach (ImportLog::query()->orderByDesc('imported_at')->orderByDesc('id')->limit(200)->get() as $l) {
            $imp[] = [
                self::when($fa, $l->imported_at, true), $l->actor_id, $l->file_name ?? '', $l->file_format ?? '',
                $fa ? FaLabels::importStatus($l->status) : $l->status, $l->error_count, $l->details ?? '',
            ];
        }
        $o->sheet($fa ? 'تاریخچه ورود' : 'Import_History',
            $fa ? ['زمان (شمسی)', 'عامل', 'فایل', 'قالب', 'وضعیت', 'تعداد خطا', 'جزئیات خطا']
                : ['Time', 'Actor', 'File', 'Format', 'Status', 'Errors', 'Error_Details'],
            $imp);

        $aud = [];
        foreach (AuditEvent::query()->orderByDesc('occurred_at')->limit(1000)->get() as $e) {
            $aud[] = [
                $e->event_id, self::when($fa, $e->occurred_at, true), $e->actor_id ?? '',
                $fa ? FaLabels::action($e->action_name) : $e->action_name,
                $fa ? FaLabels::entity($e->entity_type) : $e->entity_type, $e->entity_id ?? '',
                $e->previous_value ?? '', $e->new_value ?? '', $e->reason ?? '', $e->project_id ?? '',
            ];
        }
        $o->sheet($fa ? 'ممیزی' : 'Audit',
            $fa ? ['شناسه رویداد', 'زمان (شمسی)', 'عامل', 'اقدام', 'نوع موجودیت', 'شناسه موجودیت', 'مقدار قبلی', 'مقدار جدید', 'علت', 'شناسه پروژه']
                : ['Event_ID', 'Timestamp', 'Actor_ID', 'Action', 'Entity_Type', 'Entity_ID', 'Previous_Value', 'New_Value', 'Reason', 'Related_Project_ID'],
            $aud);

        return self::write($o->book);
    }

    /** Persian: Solar Hijri date (and time) in Tehran time; English: ISO instant. */
    private static function when(bool $fa, mixed $t, bool $withTime): string
    {
        if ($t === null || $t === '') {
            return '';
        }
        $c = $t instanceof \DateTimeInterface ? CarbonImmutable::instance($t) : CarbonImmutable::parse((string) $t);
        if (!$fa) {
            return $c->utc()->format('Y-m-d\TH:i:s.v\Z');
        }

        return $withTime ? Jalali::dateTime($c) : Jalali::date($c);
    }

    private static function round(float $v): float
    {
        return round($v, 2);
    }

    private static function write(Spreadsheet $book): string
    {
        $book->removeSheetByIndex(0); // the default empty sheet, every sheet is created explicitly
        $path = tempnam(sys_get_temp_dir(), 'aiso');
        try {
            (new Xlsx($book))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
