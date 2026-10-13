<?php

namespace App\Enums;

/**
 * What the schedule is optimised for.
 *
 *  - PRIORITY:  projects run in parallel on the shared resources, strictly by project priority. A lower-priority
 *               project only uses what a higher-priority one leaves free and can never delay it. (default)
 *  - RESOURCES: all work is scheduled as one pool so that resources stand idle as little as possible. How long the
 *               projects take does not matter.
 *  - TIME:      one chosen project finishes as early as possible, even when resources stand idle; the other projects
 *               follow in their usual order.
 */
enum PlanningGoal: string
{
    case PRIORITY = 'PRIORITY';
    case RESOURCES = 'RESOURCES';
    case TIME = 'TIME';
}
