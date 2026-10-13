<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exports\ExcelService;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\LanguageService;
use App\Services\PlanningService;
use App\Support\Text\FaTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Planning without an Excel file (management only): load the plan, check or save a draft, export it as a workbook. */
class PlanningController extends Controller
{
    public function __construct(
        private readonly PlanningService $planning,
        private readonly ExcelService $excel,
        private readonly LanguageService $lang,
    ) {
    }

    public function plan(Request $request): JsonResponse
    {
        return response()->json($this->planning->snapshot($request->query('projectId')));
    }

    /** apply=false only checks the draft (nothing is written); apply=true saves it when it is clean. */
    public function submit(Request $request): JsonResponse
    {
        $v = $this->input($request, [
            'apply' => 'boolean',
            'projectId' => 'nullable|string|max:64',
            'newProjectName' => 'nullable|string|max:200',
            'newProjectId' => 'nullable|string|max:64',
            'dueDate' => 'nullable|string|max:40',
            'resources' => 'array|max:2000',
            'operations' => 'array|max:5000',
            'predecessors' => 'array|max:20000',
        ]);
        $result = $this->planning->submit($v, (bool) ($v['apply'] ?? false), $this->actor($request));

        return response()->json($this->localized($result));
    }

    /**
     * Reads an Excel file into the plan builder. The file is checked like an import into the chosen project (nothing is
     * saved); when it passes, `draft` carries the values to fill in, otherwise the problems are listed.
     */
    public function fromExcel(Request $request): JsonResponse
    {
        $file = $request->file('file');
        if ($file === null || !$file->isValid() || $file->getSize() === 0) {
            throw ApiException::badRequest('The file is empty');
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw ApiException::badRequest('The file is larger than 10 MB');
        }
        $projectId = trim((string) $request->input('projectId', ''));
        $newName = trim((string) $request->input('newProjectName', ''));
        $target = [
            'projectId' => $projectId,
            // the name is only needed to run the checks as a new project; it is not stored here
            'newProjectName' => $projectId === '' ? ($newName === '' ? 'Draft' : $newName) : null,
            'dueDate' => $request->input('dueDate'),
        ];
        $result = $this->planning->fromWorkbook($file->getRealPath(), $file->getClientOriginalName(), $target, $this->actor($request));

        return response()->json($this->localized($result));
    }

    /** What each planning goal would give on the saved data, and the goal in force. */
    public function goals(Request $request): JsonResponse
    {
        return response()->json($this->planning->goals($request->query('focus')));
    }

    public function setGoal(Request $request): JsonResponse
    {
        $v = $this->input($request, ['goal' => 'required|string|max:20', 'focusProjectId' => 'nullable|string|max:64']);

        return response()->json($this->planning->setGoal($v['goal'], $v['focusProjectId'] ?? null, $this->actor($request)));
    }

    /** Persian requests get Persian messages. */
    private function localized(array $result): array
    {
        if ($this->lang->requestIsPersian()) {
            $result['errors'] = array_map(static fn (array $e) => ['message' => FaTranslator::translate($e['message'])] + $e, $result['errors']);
            $result['warnings'] = array_map(static fn (string $w) => FaTranslator::translate($w), $result['warnings']);
        }

        return $result;
    }

    /** The saved plan of a project as an .xlsx in the import layout (lang=fa for Persian names). */
    public function export(Request $request): Response
    {
        $lang = $request->query('lang');
        $fa = $lang === null ? $this->lang->requestIsPersian() : strcasecmp((string) $lang, 'fa') === 0;
        $projectId = $request->query('projectId');
        $name = $projectId !== null && $projectId !== '' ? (Project::query()->find($projectId)?->id ?? 'plan') : 'plan';

        return ImportController::download($this->excel->plan($fa, $projectId), ($fa ? 'AISO-برنامه-' : 'AISO-plan-') . $name . '.xlsx');
    }
}
