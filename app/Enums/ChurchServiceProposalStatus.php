<?php

declare(strict_types=1);

namespace App\Enums;

enum ChurchServiceProposalStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Stale = 'stale';
}
