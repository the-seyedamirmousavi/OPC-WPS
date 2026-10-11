<?php

namespace App\Assignment;

/** A way of turning READY operations into assignment proposals. Selected at runtime by the assignment mode. */
interface AssignmentStrategy
{
    /** 'ALGORITHM' or 'LLM'. */
    public function mode(): string;

    public function suggest(AssignmentContext $ctx): Suggestion;
}
