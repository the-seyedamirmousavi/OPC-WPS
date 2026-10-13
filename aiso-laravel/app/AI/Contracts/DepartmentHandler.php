<?php

namespace App\AI\Contracts;

use App\Models\AppUser;
use App\Models\Department;

/**
 * The only door from the AI agent to a department's data. The intent and the parameters have already been checked
 * against the registry; the handler still applies the user's own scope, limits the rows and leaves out sensitive fields.
 */
interface DepartmentHandler
{
    /** Reads data for one intent. @return array<string, mixed> */
    public function fetch(string $intent, array $params, AppUser $user, Department $department): array;

    /** Runs one operation the user has confirmed. Changes are made in a transaction, after re-checking the state. @return array<string, mixed> */
    public function execute(string $operation, array $params, AppUser $user, Department $department): array;
}
