<?php

declare(strict_types=1);

namespace App\Domain\Classification\Enums;

/**
 * Estado de la clasificación vigente de un ítem.
 */
enum ClassificationStatus: string
{
    case Suggested = 'suggested';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case NeedsReview = 'needs_review';
}
