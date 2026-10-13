<?php

namespace App\Services;

use App\Enums\AssignmentMode;
use App\Enums\SystemStatus;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\AssignmentProposal;
use App\Models\DepartmentSuggestion;
use App\Models\AssignmentRun;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Operation;
use App\Models\OperationReport;
use App\Models\PlanBaseline;
use App\Models\Predecessor;
use App\Models\Project;
use App\Models\ScheduleForce;
use App\Models\SystemSetting;
use App\Models\WorkResource;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Owner settings, system status, controlled reset. */
class AdminService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DependencyService $dependencies,
    ) {
    }

    public function view(): array
    {
        return self::toView(SystemSetting::current());
    }

    public function update(?string $projectName, ?string $messengerPlatform, ?int $maxActiveTasks, ?bool $requireApproval,
                           ?string $language, AppUser $actor): array
    {
        return DB::transaction(function () use ($projectName, $messengerPlatform, $maxActiveTasks, $requireApproval, $language, $actor) {
            $s = SystemSetting::current();
            $before = self::summary($s);
            $approvalChanged = false;
            if ($projectName !== null && trim($projectName) !== '') {
                $s->project_name = trim($projectName);
            }
            if ($messengerPlatform !== null && trim($messengerPlatform) !== '') {
                $s->messenger_platform = strtoupper(trim($messengerPlatform));
            }
            if ($language !== null && trim($language) !== '') {
                $l = strtolower(trim($language));
                if ($l !== 'fa' && $l !== 'en') {
                    throw ApiException::badRequest('language must be fa or en');
                }
                $s->language = $l;
            }
            if ($maxActiveTasks !== null) {
                if ($maxActiveTasks < 1 || $maxActiveTasks > 50) {
                    throw ApiException::badRequest('maxActiveTasksPerUser must be between 1 and 50');
                }
                $s->max_active_tasks_per_user = $maxActiveTasks;
            }
            if ($requireApproval !== null && $requireApproval !== (bool) $s->require_completion_approval) {
                $s->require_completion_approval = $requireApproval;
                $approvalChanged = true;
            }
            self::saveChanged($s);
            $this->audit->record($actor->id, 'SETTINGS_CHANGED', 'Settings', '1', $before, self::summary($s), null);
            if ($approvalChanged) {
                $this->dependencies->evaluateAll($actor->id);
            }

            return self::toView($s);
        });
    }

    /**
     * Sets or clears the Bale bot token. A token is checked for the shape Bale gives its bots (digits, a colon, then the
     * secret), stored encrypted, and never returned. Only its last four characters are ever shown.
     */
    public function setBale(?string $token, bool $clearToken, AppUser $actor): array
    {
        $token = $token === null ? null : trim($token);
        if ($token !== null && $token !== '' && preg_match('/\A\d{5,}:[A-Za-z0-9_\-]{20,}\z/', $token) !== 1) {
            throw ApiException::badRequest('The bot token must look like 123456789:ABCdef… (as given by @BotFather)');
        }

        return DB::transaction(function () use ($token, $clearToken, $actor) {
            $s = SystemSetting::current();
            $change = null;
            if ($clearToken) {
                $s->bale_bot_token = null;
                $change = 'token removed';
            } elseif ($token !== null && $token !== '') {
                $s->bale_bot_token = Crypt::encryptString($token);
                $change = 'token set';
            }
            if ($change !== null) {
                self::saveChanged($s);
                $this->audit->record($actor->id, 'BALE_SETTINGS_CHANGED', 'Settings', '1', null, $change, null);
            }

            return self::toView($s);
        });
    }

    /**
     * Stores the AI service token and model entered in the owner console. The token is encrypted with the application
     * key and is not written to the audit trail (only that it was set or removed).
     *
     * @param string|null $apiKey   a new token; null or blank leaves the stored one alone
     * @param string|null $model    a model id; blank goes back to the default
     * @param bool        $clearKey remove the stored token (the environment token, if any, applies again)
     */
    public function setLlm(?string $apiKey, ?string $model, bool $clearKey, AppUser $actor): array
    {
        $apiKey = $apiKey === null ? null : trim($apiKey);
        $model = $model === null ? null : trim($model);
        if ($apiKey !== null && $apiKey !== '' && preg_match('/\A[\x21-\x7E]{8,500}\z/', $apiKey) !== 1) {
            throw ApiException::badRequest('The token must be 8 to 500 characters without spaces');
        }
        if ($model !== null && $model !== '' && preg_match('/\A[A-Za-z0-9_.\-:\/]{1,100}\z/', $model) !== 1) {
            throw ApiException::badRequest('The model id may only contain letters, digits and . _ - : /');
        }

        return DB::transaction(function () use ($apiKey, $model, $clearKey, $actor) {
            $s = SystemSetting::current();
            $changes = [];
            if ($clearKey) {
                $s->llm_api_key = null;
                $changes[] = 'token removed';
            } elseif ($apiKey !== null && $apiKey !== '') {
                $s->llm_api_key = Crypt::encryptString($apiKey);
                $changes[] = 'token set';
            }
            if ($model !== null) {
                $s->llm_model = $model === '' ? null : $model;
                $changes[] = 'model ' . ($model === '' ? 'reset to default' : $model);
            }
            if ($changes !== []) {
                self::saveChanged($s);
                $this->audit->record($actor->id, 'LLM_SETTINGS_CHANGED', 'Settings', '1', null, implode(', ', $changes), null);
            }

            return self::toView($s);
        });
    }

    public function setAssignmentMode(AssignmentMode $mode, AppUser $actor): array
    {
        return DB::transaction(function () use ($mode, $actor) {
            $s = SystemSetting::current();
            $before = $s->assignment_mode;
            if ($before !== $mode) {
                $s->assignment_mode = $mode;
                self::saveChanged($s);
                $this->audit->record($actor->id, 'ASSIGNMENT_MODE_CHANGED', 'Settings', '1', $before, $mode, null);
            }

            return self::toView($s);
        });
    }

    public function setSystemStatus(SystemStatus $status, ?string $reason, AppUser $actor): array
    {
        if ($reason === null || trim($reason) === '') {
            throw ApiException::badRequest('A reason is required');
        }

        return DB::transaction(function () use ($status, $reason, $actor) {
            $s = SystemSetting::current();
            $before = $s->system_status;
            if ($before !== $status) {
                $s->system_status = $status;
                self::saveChanged($s);
                $this->audit->record($actor->id, 'SYSTEM_STATUS_CHANGED', 'Settings', '1', $before, $status, $reason);
            }

            return self::toView($s);
        });
    }

    /**
     * Controlled reset: removes operational data (projects, operations, resources, items, predecessors, reports,
     * proposals, notifications, manager FORCEs, the reference plan, and the department suggestions about resources).
     * Users, settings, departments with their members and permissions, and the audit trail are kept; the reset itself
     * is recorded with its reason.
     */
    public function reset(?string $reason, AppUser $actor): void
    {
        if ($reason === null || trim($reason) === '') {
            throw ApiException::badRequest('A reason is required for a reset');
        }
        DB::transaction(function () use ($reason, $actor) {
            foreach ([ScheduleForce::class, PlanBaseline::class, AssignmentProposal::class, AssignmentRun::class,
                OperationReport::class, Notification::class, Predecessor::class, Operation::class, Project::class,
                Item::class, DepartmentSuggestion::class, WorkResource::class] as $model) {
                $model::query()->delete();
            }
            $s = SystemSetting::current();
            $before = $s->data_version;
            $s->data_version = '0';
            self::saveChanged($s);
            $this->audit->record($actor->id, 'SYSTEM_RESET', 'System', '1', $before, '0', $reason);
        });
    }

    /** Increments the configuration version and saves, as the Settings service did. */
    public static function saveChanged(SystemSetting $s): void
    {
        $s->configuration_version = (int) $s->configuration_version + 1;
        $s->updated_at = now();
        $s->save();
    }

    public static function toView(SystemSetting $s): array
    {
        return [
            'projectId' => $s->project_id,
            'projectName' => $s->project_name,
            'ownerId' => $s->owner_id,
            'managerId' => $s->manager_id,
            'messengerPlatform' => $s->messenger_platform,
            'systemStatus' => $s->system_status->value,
            'dataVersion' => $s->data_version,
            'configurationVersion' => (int) $s->configuration_version,
            'assignmentMode' => $s->assignment_mode->value,
            'maxActiveTasksPerUser' => (int) $s->max_active_tasks_per_user,
            'requireCompletionApproval' => (bool) $s->require_completion_approval,
            'llmAvailable' => LlmConfig::configured() && app(LicenseService::class)->llmAllowed(),
            'llmModel' => LlmConfig::model() ?: null,
            // the token itself is never sent: only whether one is set, where it comes from and its last four characters
            'llmKeySet' => LlmConfig::apiKey() !== null,
            'llmKeySource' => LlmConfig::keySource(),
            'llmKeyHint' => LlmConfig::keyHint(),
            'llmBaseUrl' => LlmConfig::baseUrl(),
            'baleKeySet' => BaleConfig::configured(),
            'baleKeySource' => BaleConfig::keySource(),
            'baleKeyHint' => BaleConfig::keyHint(),
            'language' => $s->language,
        ];
    }

    private static function summary(SystemSetting $s): string
    {
        return 'project=' . $s->project_name . ', messenger=' . $s->messenger_platform . ', maxActive='
            . $s->max_active_tasks_per_user . ', requireApproval=' . ($s->require_completion_approval ? 'true' : 'false')
            . ', language=' . $s->language;
    }
}
