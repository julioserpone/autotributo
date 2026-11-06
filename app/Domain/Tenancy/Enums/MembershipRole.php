<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Accountant = 'accountant';
    case Assistant = 'assistant';
    case Viewer = 'viewer';
}
