<?php

namespace App\Services;

use App\AI\ActionExecutor;
use App\AI\DepartmentRegistry;
use App\AI\Dialogue\ConversationStateStore;
use App\AI\ProtocolValidator;
use App\Enums\ProjectStatus;
use App\Exceptions\ApiException;
use App\Imports\ImportService;
use App\Imports\ParsedData;
use App\Models\AiRun;
use App\Models\AppUser;
use App\Models\ChatConversation;
use App\Models\ChatUpload;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Excel files in the assistant chat. The file is checked (.xlsx, size), kept privately on the server and read only by the
 * import pipeline (ImportService): the AI never sees its cells, only counts. The answer is a preview: a table of the rows
 * with their errors and warnings, the counts and the effect on the schedule. Importing is an action (import_plan, high
 * risk) proposed under the owner's approval policy; it runs the same import, with every rule and the audit trail.
 *
 * User rows in a file are left out here (importing them creates accounts with temporary passwords, which belong on the
 * import page, not in a chat). "Only the valid rows" drops the rows with errors, and the rows that depended on them.
 */
class ChatImportService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const TTL_HOURS = 24;
    private const PREVIEW_ROWS = 40;

    public function __construct(
        private readonly ImportService $importer,
        private readonly ActionExecutor $actions,
        private readonly ProtocolValidator $validator,
        private readonly DepartmentRegistry $registry,
        private readonly ConversationStateStore $states,
    ) {
    }

    /**
     * Receives a file in a conversation: stores it, previews it and proposes importing it.
     *
     * @return array the same shape as a chat answer, plus `attachment` (the preview)
     */
    public function upload(AppUser $user, ?UploadedFile $file, ?int $conversationId, ?string $projectId): array
    {
        if (!$user->allows('production.manage')) {
            throw ApiException::forbidden('Only a person who manages production can import a plan');
        }
        $production = $this->registry->find($user, 'production');
        if ($production === null) {
            throw ApiException::forbidden('The production department is not available to you');
        }
        self::check($file);
        $conversation = $conversationId === null ? null : ChatConversation::query()->where('user_id', $user->id)->find($conversationId);
        if ($conversationId !== null && $conversation === null) {
            throw ApiException::notFound("Conversation {$conversationId} not found");
        }

        $id = (string) Str::uuid();
        $stored = Storage::disk('local')->putFileAs('chat-uploads', $file, "{$id}.xlsx");
        $now = CarbonImmutable::now();
        $upload = ChatUpload::query()->create([
            'id' => $id, 'user_id' => $user->id, 'conversation_id' => $conversation?->id, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $stored, 'size' => (int) $file->getSize(), 'created_at' => $now, 'expires_at' => $now->addHours(self::TTL_HOURS),
        ]);

        $target = ['project_id' => $projectId];
        [$all] = $this->analyze($upload, $user, $target, false, false);
        $valid = null;
        if ($all['status'] === 'REJECTED' && self::rowErrors($all) !== []) {
            [$valid, , $dropped] = $this->analyze($upload, $user, $target, true, false);
            $valid['dropped_rows'] = $dropped;
        }
        $attachment = $this->attachment($upload, $all, $valid);

        // the proposal: import everything when the file is clean, or only the valid rows when that leaves something
        $params = ['upload_id' => $id] + ($projectId ? ['project_id' => $projectId] : []);
        $importable = in_array($all['status'], ['VALID', 'PRIORITY_REQUIRED'], true);
        $validImportable = $valid !== null && in_array($valid['status'], ['VALID', 'PRIORITY_REQUIRED'], true) && self::opCount($valid) > 0;
        if (!$importable && $validImportable) {
            $params['only_valid'] = true;
        }
        $run = AiRun::query()->create(['user_id' => $user->id, 'question' => 'Excel: ' . $upload->file_name, 'status' => 'DONE', 'created_at' => $now]);
        $pending = [];
        if ($importable || $validImportable) {
            $action = $this->validator->action(['department' => 'production', 'operation' => 'import_plan', 'params' => $params,
                'reason' => $importable ? 'the file passed every check' : 'only the rows without errors'], ['production' => $production], $why);
            if ($action !== null) {
                $pending = $this->actions->propose($run, $user, [$action])['pending'];
            }
        }
        $text = self::summary($upload->file_name, $all, $valid, $pending !== []);

        return $this->store($user, $conversation, $upload, $text, $attachment, $pending, $run->id);
    }

    /**
     * Imports a file that was sent to the assistant (the import_plan action). Runs as the person who sent it.
     *
     * @return array<string, mixed> what was imported (no passwords: user rows are never imported here)
     */
    public function execute(array $params, AppUser $user): array
    {
        $upload = ChatUpload::query()->find($params['upload_id']);
        if ($upload === null || $upload->user_id !== $user->id) {
            throw ApiException::notFound('That file was not found');
        }
        if ($upload->imported_at !== null) {
            throw ApiException::conflict('This file was already imported');
        }
        if ($upload->expires_at !== null && $upload->expires_at->isPast()) {
            throw ApiException::conflict('This file has expired; send it again');
        }
        [$result, , $dropped] = $this->analyze($upload, $user, $params, (bool) ($params['only_valid'] ?? false), true);
        if ($result['status'] !== 'APPLIED') {
            throw ApiException::badRequest('The file was not imported: ' . implode('; ', array_map(static fn (array $e) => $e['message'], array_slice($result['errors'], 0, 5))));
        }
        $upload->update(['imported_at' => CarbonImmutable::now()]);
        $finish = null;
        foreach ($result['impact']['proposed']['projects'] ?? [] as $p) {
            if ($p['projectId'] === $result['projectId']) {
                $finish = $p['finishAt'];
            }
        }

        return [
            'status' => 'IMPORTED', 'project_id' => $result['projectId'], 'new_project' => $result['newProject'],
            'counts' => $result['counts'], 'skipped_rows' => count($dropped), 'planned_end' => $finish,
        ];
    }

    /**
     * Runs the import pipeline on the file: a dry run, or the import itself. With $onlyValid the rows with errors are
     * dropped and the check repeated (rows that depended on a dropped row fail next and are dropped too).
     *
     * @return array{0: array, 1: ParsedData, 2: string[]} the import result, the data, the dropped rows ("sheet row N")
     */
    private function analyze(ChatUpload $upload, AppUser $user, array $params, bool $onlyValid, bool $apply): array
    {
        $path = Storage::disk('local')->path($upload->path);
        $target = $this->target($upload, $params);
        $drop = [];
        for ($attempt = 0; ; $attempt++) {
            $d = $this->importer->parseWorkbook($path);
            if ($d->users !== []) {
                $d->warnings[] = count($d->users) . ' user row(s) were left out: people are added on the import page or in user management, not through the assistant.';
                $d->users = [];
            }
            if ($onlyValid) {
                self::dropRows($d, $drop);
            }
            $last = $attempt >= 4 || !$onlyValid;
            $result = $this->importer->processParsed($d, $upload->file_name, false, $user, $target);
            if ($onlyValid && $result['status'] === 'REJECTED' && !$last) {
                $more = array_diff(array_keys(self::rowErrors($result)), array_keys($drop));
                if ($more !== []) {
                    foreach ($more as $k) {
                        $drop[$k] = true;
                    }
                    continue;
                }
            }
            if ($apply && in_array($result['status'], ['VALID', 'PRIORITY_REQUIRED'], true)) {
                $d = $this->importer->parseWorkbook($path);
                $d->users = [];
                if ($onlyValid) {
                    self::dropRows($d, $drop);
                }
                $result = $this->importer->processParsed($d, $upload->file_name, true, $user, $target);
            }

            return [$result, $d, array_keys($drop)];
        }
    }

    /** Into the project named, or a new project named after the file, added after the active ones. */
    private function target(ChatUpload $upload, array $params): array
    {
        if (!empty($params['project_id'])) {
            return ['projectId' => $params['project_id']];
        }
        $name = trim((string) ($params['new_project_name'] ?? '')) ?: trim(pathinfo($upload->file_name, PATHINFO_FILENAME));
        $order = Project::query()->where('status', ProjectStatus::ACTIVE->value)->orderBy('priority')->orderBy('id')->pluck('id')->all();
        $order[] = ImportService::NEW;

        return ['newProjectName' => mb_substr($name === '' ? 'Excel' : $name, 0, 200), 'ranking' => $order];
    }

    /** Removes the dropped rows (and their errors) from the data. @param array<string, true> $drop keys "sheet|row" */
    private static function dropRows(ParsedData $d, array $drop): void
    {
        $keep = static fn (string $sheet) => static fn (array $r) => !isset($drop["{$sheet}|{$r['row']}"]);
        $d->resources = array_values(array_filter($d->resources, $keep($d->resourceSheet)));
        $d->items = array_values(array_filter($d->items, $keep($d->itemSheet)));
        $d->ops = array_values(array_filter($d->ops, $keep($d->opSheet)));
        $d->preds = array_values(array_filter($d->preds, $keep($d->predSheet)));
        $d->errors = array_values(array_filter($d->errors, static fn (array $e) => !isset($drop["{$e['sheet']}|{$e['row']}"])));
    }

    /** The errors that belong to a row ("sheet|row" => messages); file-level errors (row 0) cannot be dropped. */
    private static function rowErrors(array $result): array
    {
        $out = [];
        foreach ($result['errors'] as $e) {
            if ((int) $e['row'] > 0) {
                $out["{$e['sheet']}|{$e['row']}"][] = $e['message'];
            }
        }

        return $out;
    }

    private static function opCount(array $result): int
    {
        foreach ($result['counts'] as $c) {
            if (($c['entity'] ?? null) === 'operations') {
                return (int) (($c['created'] ?? 0) + ($c['updated'] ?? 0) + ($c['count'] ?? 0));
            }
        }

        return 0;
    }

    /** The preview the chat shows: the operation rows with their problems, the counts, file-level errors and the effect on the plan. */
    private function attachment(ChatUpload $upload, array $all, ?array $valid): array
    {
        $d = $this->importer->parseWorkbook(Storage::disk('local')->path($upload->path));
        $byRow = self::rowErrors($all);
        $rows = [];
        foreach (array_slice($d->ops, 0, self::PREVIEW_ROWS) as $o) {
            $problems = $byRow["{$d->opSheet}|{$o['row']}"] ?? [];
            $rows[] = [
                'row' => $o['row'], 'id' => $o['id'], 'name' => mb_substr($o['name'], 0, 120), 'resource' => $o['resourceId'],
                'hours' => round($o['prep'] + $o['transport'] + $o['setup'] + $o['direct'], 2), 'ok' => $problems === [],
                'problems' => array_map(static fn (string $m) => mb_substr($m, 0, 200), $problems),
            ];
        }
        $finish = null;
        foreach ($all['impact']['proposed']['projects'] ?? [] as $p) {
            if ($p['projectId'] === $all['projectId']) {
                $finish = $p['finishAt'];
            }
        }

        return [
            'type' => 'excel_preview', 'uploadId' => $upload->id, 'fileName' => $upload->file_name, 'status' => $all['status'],
            'format' => $all['format'], 'project' => ['id' => $all['projectId'], 'name' => $all['projectName'], 'isNew' => $all['newProject']],
            'counts' => ['operations' => count($d->ops), 'resources' => count($d->resources), 'predecessors' => count($d->preds), 'items' => count($d->items)],
            'rows' => $rows, 'moreRows' => max(0, count($d->ops) - self::PREVIEW_ROWS),
            'errors' => array_map(static fn (array $e) => ['sheet' => $e['sheet'], 'row' => $e['row'], 'column' => $e['column'], 'message' => mb_substr($e['message'], 0, 200)],
                array_slice($all['errors'], 0, 50)),
            'errorCount' => count($all['errors']), 'warnings' => array_slice($all['warnings'], 0, 20),
            'plannedFinish' => $finish,
            'validOnly' => $valid === null ? null : ['status' => $valid['status'], 'droppedRows' => count($valid['dropped_rows'])],
        ];
    }

    /** The assistant's text for the preview: counts only (cell content is data, never repeated as instructions). */
    private static function summary(string $file, array $all, ?array $valid, bool $proposed): string
    {
        $errors = count($all['errors']);
        $text = "فایل «{$file}» با همان قواعد ورود اطلاعات بررسی شد. ";
        $text .= match (true) {
            in_array($all['status'], ['VALID', 'PRIORITY_REQUIRED'], true) => 'همهٔ ردیف‌ها درست‌اند.',
            $valid !== null && $proposed => "{$errors} خطا پیدا شد. اگر فقط ردیف‌های درست را وارد کنی، " . count($valid['dropped_rows']) . ' ردیف کنار گذاشته می‌شود.',
            default => "{$errors} خطا پیدا شد و هیچ ردیفی قابل ورود نیست. جزئیات خطاها در جدول است.",
        };
        if ($proposed) {
            $text .= ' جدول پیش‌نمایش را ببین؛ وارد کردن با تأیید تو (طبق سیاست تأیید) انجام می‌شود و تا آن موقع چیزی ذخیره نشده است.';
        }

        return $text;
    }

    /** Saves the exchange in the conversation (a new one when none is given) and notes the file in the dialogue state. */
    private function store(AppUser $user, ?ChatConversation $conversation, ChatUpload $upload, string $text, array $attachment, array $pending, int $runId): array
    {
        $lock = Cache::lock('aiso:chat:' . $user->id . ':' . ($conversation?->id ?? 'new'), 60);
        if (!$lock->get()) {
            throw ApiException::conflict('The assistant is still answering your previous message in this conversation. Wait for it, then send again.');
        }
        try {
            return DB::transaction(function () use ($user, $conversation, $upload, $text, $attachment, $pending, $runId) {
                $now = CarbonImmutable::now();
                $conversation ??= ChatConversation::query()->create([
                    'user_id' => $user->id, 'title' => mb_substr('📎 ' . $upload->file_name, 0, 60), 'created_at' => $now, 'updated_at' => $now,
                ]);
                $upload->update(['conversation_id' => $conversation->id]);
                [$state, $version] = $this->states->load($conversation);
                // the model may later import it ("فقط ردیف‌های درست را اضافه کن"): it knows the file by id and counts, not its cells
                $state->focus('upload_id', $upload->id, $upload->file_name);
                $state->data['recent'][] = ['tool' => 'excel_upload', 'args' => ['upload_id' => $upload->id], 'ok' => true,
                    'brief' => "{$attachment['counts']['operations']} operations, {$attachment['errorCount']} errors, status {$attachment['status']}"];
                $state->data['recent'] = array_slice($state->data['recent'], -(int) config('aiso.agent.dialogue.recent_results'));
                $state->addTurn('📎 ' . $upload->file_name, $text, false);
                $this->states->save($conversation, $state, $version);
                $conversation->messages()->create(['role' => 'user', 'content' => '📎 ' . $upload->file_name, 'created_at' => $now]);
                $conversation->messages()->create(['role' => 'assistant', 'content' => $text, 'created_at' => $now, 'run_id' => $runId, 'attachment' => $attachment]);
                $conversation->update(['updated_at' => $now]);

                return ['conversationId' => $conversation->id, 'reply' => $text, 'model' => '', 'pending_actions' => $pending, 'sources' => [],
                    'executed' => [], 'limited' => false, 'trace' => [], 'choices' => [], 'attachment' => $attachment];
            });
        } finally {
            $lock->release();
        }
    }

    /** .xlsx only (name and content), not empty, at most 5 MB. */
    private static function check(?UploadedFile $file): void
    {
        if ($file === null || !$file->isValid() || $file->getSize() === 0) {
            throw ApiException::badRequest('The file is empty');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw ApiException::badRequest('The file is larger than 5 MB');
        }
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            throw ApiException::badRequest('Only .xlsx files can be sent');
        }
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 4);
        if ($head !== "PK\x03\x04") {
            throw ApiException::badRequest('The file is not a valid .xlsx workbook');
        }
    }
}
