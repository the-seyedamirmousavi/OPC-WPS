<?php

namespace App\Enums;

enum ReportType: string
{
    case ASSIGN = 'ASSIGN';
    case START = 'START';
    case PROGRESS = 'PROGRESS';
    case COMPLETE = 'COMPLETE';
    case BLOCK = 'BLOCK';
    case UNBLOCK = 'UNBLOCK';
    case COMMENT = 'COMMENT';
    case APPROVE = 'APPROVE';
    case CANCEL = 'CANCEL';
    case UNASSIGN = 'UNASSIGN';
}
