<?php

namespace App\Enums;

enum ProposalStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case SUPERSEDED = 'SUPERSEDED';
}
