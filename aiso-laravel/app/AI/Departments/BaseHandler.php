<?php

namespace App\AI\Departments;

use App\AI\Contracts\DepartmentHandler;
use App\AI\DepartmentRegistry;
use App\AI\Semantic\SemanticRanker;
use App\AI\SensitiveDataFilter;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\Department;
use App\Services\AgentService;

/**
 * Shared rules of the handlers: a hard row limit, a result shape (`data` and `truncated`), no sensitive fields, and
 * a role check that does not rely on the registry alone.
 */
abstract class BaseHandler implements DepartmentHandler
{
    /** The capability that lets a person use this department's handler; null means every person (not an agent). */
    abstract protected function capability(): ?string;

    abstract protected function read(string $intent, array $params, AppUser $user, Department $department): array;

    protected function run(string $operation, array $params, AppUser $user, Department $department): array
    {
        throw ApiException::badRequest("Operation {$operation} is not available here");
    }

    final public function fetch(string $intent, array $params, AppUser $user, Department $department): array
    {
        $this->guard($user);
        $this->guardIntent($intent, $user, $department);
        $result = $this->read($intent, $params, $user, $department);

        return ['data' => SensitiveDataFilter::clean($result['data']), 'truncated' => (bool) ($result['truncated'] ?? false)];
    }

    final public function execute(string $operation, array $params, AppUser $user, Department $department): array
    {
        $this->guard($user);

        return SensitiveDataFilter::clean($this->run($operation, $params, $user, $department));
    }

    private function guard(AppUser $user): void
    {
        if (!$user->allows($this->capability())) {
            throw ApiException::forbidden('Your role does not allow this');
        }
    }

    /** An intent may need a capability of its own (config: intents.{name}.capability), e.g. the owner-only performance data. */
    private function guardIntent(string $intent, AppUser $user, Department $department): void
    {
        $kind = DepartmentRegistry::kindOf($department);
        $capability = config("aiso.agent.kinds.{$kind}.intents.{$intent}.capability");
        if ($capability !== null && !$user->allows($capability)) {
            throw ApiException::forbidden('Your role does not allow this');
        }
    }

    /**
     * A moment as the people of the company read it: local time (the working calendar's timezone, Tehran by default),
     * "Y-m-d H:i". Times are stored in UTC; showing them raw would be hours off.
     */
    protected static function local(?\DateTimeInterface $t): ?string
    {
        return $t === null ? null : \Carbon\CarbonImmutable::instance($t)->setTimezone((string) config('aiso.calendar.timezone', 'Asia/Tehran'))->format('Y-m-d H:i');
    }

    /** The row limit asked for, never above the configured maximum. */
    protected static function limit(array $params): int
    {
        $max = (int) config('aiso.agent.max_rows');

        return max(1, min($max, (int) ($params['limit'] ?? 20)));
    }

    /**
     * The two operations about AI agents, shared by the departments that offer them. Null when the operation is not one.
     *
     * @return array<string, mixed>|null
     */
    protected function agentOperation(string $operation, array $params, AppUser $user): ?array
    {
        $agents = app(AgentService::class);
        if ($operation === 'create_agent') {
            $agent = $agents->create($user, $params['name'], $params['instructions'], $params['scope'] ?? null);
            $task = empty($params['task']) ? null : $agents->assignTask($user, $agent['id'], $params['task']);

            return ['agent_id' => $agent['id'], 'name' => $agent['name'], 'scope' => $agent['scope'], 'task_id' => $task['id'] ?? null];
        }
        if ($operation === 'run_agent_task') {
            $task = $agents->assignTask($user, $params['agent_id'], $params['task']);

            return ['agent_id' => $params['agent_id'], 'task_id' => $task['id']];
        }

        return null;
    }

    /**
     * Semantic search over candidate rows the handler already scoped to the user: ranks them by how well their text fits
     * the query (names and ids; Persian and English), and keeps those above the minimum score.
     *
     * @param array<int, array{id: string, text: string, row: array}> $candidates
     * @return array{data: array<int, mixed>, truncated: bool}
     */
    protected static function search(string $query, array $candidates, int $limit): array
    {
        $docs = array_map(fn (array $c) => $c['text'], $candidates);
        $scores = [];
        $ranker = app(SemanticRanker::class);
        foreach ($ranker->rank($query, $docs, count($docs)) as [$i, $score]) {
            $scores[$i] = $score;
        }
        // naming an id is the most exact search there is
        foreach ($candidates as $i => $c) {
            if ($c['id'] !== '' && stripos($query, $c['id']) !== false) {
                $scores[$i] = 1.0;
            }
        }
        arsort($scores);
        $min = $ranker->threshold('search_min');
        $rows = [];
        foreach ($scores as $i => $score) {
            if ($score >= $min) {
                $rows[] = $candidates[$i]['row'] + ['score' => round($score, 2)];
            }
        }

        return self::cut($rows, $limit);
    }

    /**
     * Cuts a list to the limit.
     *
     * @param iterable<mixed> $rows fetched with one row more than the limit, so truncation can be reported
     * @return array{data: array<int, mixed>, truncated: bool}
     */
    protected static function cut(iterable $rows, int $limit): array
    {
        $all = [...$rows];

        return ['data' => array_slice($all, 0, $limit), 'truncated' => count($all) > $limit];
    }
}
