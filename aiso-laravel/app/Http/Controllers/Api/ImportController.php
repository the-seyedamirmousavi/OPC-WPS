<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Imports\ImportService;
use App\Models\ImportLog;
use App\Services\LanguageService;
use App\Services\ProjectService;
use App\Exports\ExcelService;
use App\Support\Text\FaTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class ImportController extends Controller
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly ImportService $importer,
        private readonly ExcelService $excel,
        private readonly LanguageService $lang,
    ) {
    }

    /**
     * apply=false validates only; apply=true validates and, when the file is clean, imports it. Target project: projectId
     * (existing) or newProjectName (+ optional newProjectId, dueDate). When a new project joins active ones, ranking
     * (comma separated project ids, first = highest priority, NEW = the new project) is required; without it the answer
     * is status PRIORITY_REQUIRED together with the schedule impact of the default order.
     */
    public function upload(Request $request): JsonResponse
    {
        $file = $request->file('file');
        if ($file === null || !$file->isValid() || $file->getSize() === 0) {
            throw ApiException::badRequest('The file is empty');
        }
        $ranking = trim((string) $request->input('ranking', ''));
        $order = $ranking === '' ? null : array_values(array_filter(array_map('trim', explode(',', $ranking)), static fn ($x) => $x !== ''));
        $target = [
            'projectId' => $request->input('projectId'),
            'newProjectName' => $request->input('newProjectName'),
            'newProjectId' => $request->input('newProjectId'),
            'dueDate' => ProjectService::parseDue($request->input('dueDate')),
            'ranking' => $order,
        ];
        $result = $this->importer->process($file->getRealPath(), $file->getClientOriginalName(),
            $request->boolean('apply'), $this->actor($request), $target);

        return response()->json($this->localized($result));
    }

    /** Persian requests get Persian messages; the stored history stays in the system language. */
    private function localized(array $r): array
    {
        if (!$this->lang->requestIsPersian()) {
            return $r;
        }
        $r['errors'] = array_map(static fn (array $e) => $e + ['message' => FaTranslator::translate($e['message'])], $r['errors']);
        $r['warnings'] = array_map(static fn (string $w) => FaTranslator::translate($w), $r['warnings']);

        return $r;
    }

    /** The master template. lang=fa gives Persian sheet names, headers and sample values (also accepted on import). */
    public function template(Request $request): Response
    {
        $lang = $request->query('lang');
        $fa = $lang === null ? $this->lang->requestIsPersian() : strcasecmp((string) $lang, 'fa') === 0;

        return $this->download($this->excel->template($fa), $fa ? 'AISO-قالب-اصلی.xlsx' : 'AISO-master-template.xlsx');
    }

    public function history(): JsonResponse
    {
        $rows = ImportLog::query()->orderByDesc('imported_at')->orderByDesc('id')->limit(50)->get();

        return response()->json($rows->map(static fn (ImportLog $l) => [
            'id' => $l->id,
            'importedAt' => $l->imported_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'actorId' => $l->actor_id,
            'fileName' => $l->file_name,
            'format' => $l->file_format,
            'status' => $l->status,
            'errorCount' => (int) $l->error_count,
            'summary' => $l->summary,
            'details' => $l->details,
        ])->values());
    }

    /** An .xlsx download with a UTF-8 file name (Persian names included). */
    public static function download(string $bytes, string $name): Response
    {
        return response($bytes, 200, [
            'Content-Type' => self::XLSX,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, 'report.xlsx'),
        ]);
    }
}
