<?php

namespace App\Scheduling;

/**
 * A FORCE that is not honoured.
 *
 * @param string   $kind          one of the KIND_* constants
 * @param bool     $proven        true = no schedule at all can honour it; false = only this heuristic could not
 * @param string[] $related       the operations it collides with (CAPACITY), the binding predecessor chain (PRECEDENCE),
 *                                the older FORCE it would block (BLOCKS_FORCE)
 * @param float    $earliestStart earliest start the constraint allows (PRECEDENCE), end of the clashing work (CAPACITY),
 *                                the start actually planned (NOT_FOUND), the older FORCE's start (BLOCKS_FORCE),
 *                                0 (START_IN_PAST); NAN when unknown
 */
final class Conflict
{
    /** the forced start is before now */
    public const KIND_START_IN_PAST = 'START_IN_PAST';
    /** the predecessor chain cannot be done by the forced start even with unlimited resources */
    public const KIND_PRECEDENCE = 'PRECEDENCE';
    /** it would make an older FORCE impossible by precedence */
    public const KIND_BLOCKS_FORCE = 'BLOCKS_FORCE';
    /** forced and running work on the resource overlap beyond its capacity */
    public const KIND_CAPACITY = 'CAPACITY';
    /** no exact contradiction was found, but this heuristic found no schedule that honours the FORCE */
    public const KIND_NOT_FOUND = 'NOT_FOUND';

    /** @param string[] $related */
    public function __construct(
        public readonly string $operationId,
        public readonly string $kind,
        public readonly bool $proven,
        public readonly array $related,
        public readonly float $requestedStart,
        public readonly float $earliestStart,
    ) {
    }
}
