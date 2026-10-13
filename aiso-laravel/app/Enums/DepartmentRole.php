<?php

namespace App\Enums;

/** A person's role inside one department. The permissions of each role are set per department by the owner. */
enum DepartmentRole: string
{
    case HEAD = 'HEAD';
    case MEMBER = 'MEMBER';
}
