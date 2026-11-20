<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Enums;

enum FiscalRiskStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}