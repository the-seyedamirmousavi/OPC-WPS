<?php

namespace App\AI\Dialogue;

use App\AI\DepartmentRegistry;
use App\AI\SensitiveDataFilter;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Runs one read tool for one person. The single way the assistant reads data:
 *  - the tool must be in the person's ToolRegistry list (department they may open, capability they hold);
 *  - unknown parameters are refused, missing required ones are reported (not guessed), the rest are validated by the
 *    rules in the config;
 *  - the department handler checks the role again (BaseHandler::guard and guardIntent) and removes sensitive fields;
 *  - the result is cut to fit the model's budget, and says so when it was cut.
 *
 * @phpstan-type ToolResult array{tool: string, ok: bool, status: string, args: array, data?: mixed, truncated?: bool,
 *     shown?: int, total_known?: ?int, error?: string, missing?: string[]}
 */
class ToolExecutor
{
    public const OK = 'ok';
    public const MISSING = 'missing_parameters';
    public const INVALID = 'invalid_parameters';
    public const UNKNOWN = 'unknown_tool';
    public const FORBIDDEN = 'forbidden';
    public const FAILED = 'failed';

    public function __construct(private readonly DepartmentRegistry $registry)
    {
    }

    /**
     * @param array<string, array> $tools ToolRegistry::forUser
     * @param array<string, array> $allowed DepartmentRegistry::forUser (the department records)
     * @return array<string, mixed> ToolResult
     */
    public function run(AppUser $user, array $tools, array $allowed, string $name, mixed $args): array
    {
        $base = ['tool' => $name, 'args' => is_array($args) ? $args : []];
        $tool = $tools[$name] ?? null;
        if ($tool === null) {
            // not offered to this person: unknown, or a department or capability they do not have. The same answer for both.
            return $base + ['ok' => false, 'status' => self::UNKNOWN, 'error' => 'this tool is not available to this user'];
        }
        if (!is_array($args) || ($args !== [] && array_is_list($args))) {
            return $base + ['ok' => false, 'status' => self::INVALID, 'error' => 'args must be an object'];
        }

        $unknown = array_diff(array_keys($args), array_keys($tool['params']));
        if ($unknown !== []) {
            return $base + ['ok' => false, 'status' => self::INVALID, 'error' => 'unknown parameter ' . implode(', ', $unknown)];
        }
        $args = array_filter($args, static fn ($v) => $v !== null && $v !== '');
        $missing = array_values(array_diff($tool['required'], array_keys($args)));
        if ($missing !== []) {
            return ['tool' => $name, 'args' => $args, 'ok' => false, 'status' => self::MISSING, 'missing' => $missing,
                'error' => 'missing ' . implode(', ', $missing)];
        }
        $v = Validator::make($args, $tool['params']);
        if ($v->fails()) {
            return ['tool' => $name, 'args' => $args, 'ok' => false, 'status' => self::INVALID, 'error' => implode('; ', $v->errors()->all())];
        }
        $args = $v->validated();

        $entry = $allowed[$tool['slug']] ?? null;
        if ($entry === null) {
            return ['tool' => $name, 'args' => $args, 'ok' => false, 'status' => self::UNKNOWN, 'error' => 'this tool is not available to this user'];
        }
        try {
            $res = $this->registry->handler($entry['kind'])->fetch($tool['intent'], $args, $user, $entry['department']);
        } catch (ApiException $e) {
            $status = $e->status === 403 ? self::FORBIDDEN : self::FAILED;

            return ['tool' => $name, 'args' => $args, 'ok' => false, 'status' => $status,
                'error' => $status === self::FORBIDDEN ? 'not allowed for this user' : SensitiveDataFilter::scrub($e->getMessage())];
        } catch (\Throwable $e) {
            // the cause stays in the log; the model and the user only learn that the data could not be read
            Log::warning('AI tool failed', ['tool' => $name, 'error' => $e->getMessage()]);

            return ['tool' => $name, 'args' => $args, 'ok' => false, 'status' => self::FAILED, 'error' => 'the data could not be read'];
        }

        return ['tool' => $name, 'args' => $args, 'ok' => true, 'status' => self::OK] + self::fit($res['data'], $res['truncated']);
    }

    /**
     * Cuts a result to the character budget. A list loses its last rows (and says how many it shows); anything else that is
     * still too long is replaced by a note. Never presents a partial result as complete.
     *
     * @return array{data: mixed, truncated: bool, shown?: int}
     */
    public static function fit(mixed $data, bool $truncated): array
    {
        $max = (int) config('aiso.agent.dialogue.max_result_chars');
        $size = static fn ($d) => mb_strlen((string) json_encode($d, JSON_UNESCAPED_UNICODE));
        if ($size($data) <= $max) {
            return ['data' => $data, 'truncated' => $truncated] + (is_array($data) && array_is_list($data) ? ['shown' => count($data)] : []);
        }
        if (is_array($data) && array_is_list($data)) {
            $rows = $data;
            while ($rows !== [] && $size($rows) > $max) {
                array_pop($rows);
            }

            return ['data' => $rows, 'truncated' => true, 'shown' => count($rows)];
        }

        return ['data' => ['note' => 'the result was too large to show; ask for a narrower question'], 'truncated' => true];
    }
}
