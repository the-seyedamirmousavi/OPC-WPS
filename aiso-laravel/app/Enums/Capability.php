<?php

namespace App\Enums;

/**
 * What a role may do. The catalogue is fixed in the code, the roles that hold these capabilities are data (app_role) and
 * the company defines them. OWNER holds every capability and is not listed here: ownership is an identity, not a grant.
 */
enum Capability: string
{
    /** See the production workspace: projects, assignment, operations, plan, planner and reports, and the manager dashboard. */
    case PRODUCTION_VIEW = 'production.view';
    /** Change production: assign and approve work, cancel or unblock operations, edit projects, build plans, FORCE, import. */
    case PRODUCTION_MANAGE = 'production.manage';
    /** See the list of users. */
    case USERS_VIEW = 'users.view';
    /** Create users, change their role and activity, reset passwords and delete accounts that have no work history. */
    case USERS_MANAGE = 'users.manage';
    /** Create departments, set their members and what each department role may do. */
    case DEPARTMENTS_MANAGE = 'departments.manage';
    /** Pseudo-capability: the role's operations are its own tasks (the receives_tasks flag). Not granted as a capability. */
    case TASKS_RECEIVE = 'tasks.receive';
    /**
     * Individual work and audit data: what one person did, their history, the audit log. Owner only. It is for reporting
     * work; the assistant never ranks or scores people with it unless the owner explicitly asks. Not grantable to any role.
     */
    case PERFORMANCE_VIEW = 'performance.view';

    /** The capabilities a company may grant to a role. */
    public static function grantable(): array
    {
        return array_values(array_map(static fn (self $c) => $c->value,
            array_filter(self::cases(), static fn (self $c) => !in_array($c, [self::TASKS_RECEIVE, self::PERFORMANCE_VIEW], true))));
    }

    /** Capabilities whose holder manages other people's work: only the owner may give them out. */
    public static function managerial(): array
    {
        return [self::PRODUCTION_MANAGE->value, self::USERS_MANAGE->value, self::DEPARTMENTS_MANAGE->value];
    }
}
