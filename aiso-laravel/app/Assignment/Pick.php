<?php

namespace App\Assignment;

/** A proposed assignment of one READY operation to one user. */
final class Pick
{
    public function __construct(public readonly string $operationId, public readonly string $userId, public readonly string $reason)
    {
    }
}
