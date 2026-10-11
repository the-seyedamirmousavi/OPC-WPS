<?php

namespace App\Assignment;

/** A READY operation that was left unassigned, and why. */
final class Skip
{
    public function __construct(public readonly string $operationId, public readonly string $reason)
    {
    }
}
