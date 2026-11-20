<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Enums;

enum FiscalRiskSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}