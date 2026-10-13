<?php

namespace App\Enums;

/** What a department role may do. The owner may always do everything. */
enum DepartmentPermission: string
{
    /** Open the department's panel. Without it the department is not in the person's menu. */
    case PANEL_VIEW = 'PANEL_VIEW';
    /** Edit the department's resources and confirm they are up to date. */
    case RESOURCES_MANAGE = 'RESOURCES_MANAGE';
    /** Send suggestions to the manager. */
    case SUGGESTIONS_SUBMIT = 'SUGGESTIONS_SUBMIT';

    /** The permissions a new department starts with: the head may do everything, members may view and suggest. */
    public static function defaults(DepartmentRole $role): array
    {
        return match ($role) {
            DepartmentRole::HEAD => [self::PANEL_VIEW, self::RESOURCES_MANAGE, self::SUGGESTIONS_SUBMIT],
            DepartmentRole::MEMBER => [self::PANEL_VIEW, self::SUGGESTIONS_SUBMIT],
        };
    }
}
