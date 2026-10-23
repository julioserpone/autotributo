<?php

declare(strict_types=1);

namespace App\Domain\Sri\Enums;

/**
 * Estado de un objeto descubierto durante la reconciliación del robot.
 */
enum SriSyncRunFileStatus: string
{
    case Pending = 'pending';
    case Available = 'available';
    case Missing = 'missing';
    case Invalid = 'invalid';
    case Processed = 'processed';

    public function isSuccessful(): bool
    {
        return in_array($this, [self::Available, self::Processed], true);
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::Missing, self::Invalid], true);
    }
}
