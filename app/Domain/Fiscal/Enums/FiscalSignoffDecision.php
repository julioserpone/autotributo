<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Enums;

enum FiscalSignoffDecision: string
{
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
}
