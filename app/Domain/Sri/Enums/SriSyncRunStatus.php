<?php

declare(strict_types=1);

namespace App\Domain\Sri\Enums;

/**
 * Estados de una sincronización con el robot SRI.
 *
 * La transición es explícita para que un run completado no vuelva a un estado
 * activo por un callback tardío o un reintento.
 */
enum SriSyncRunStatus: string
{
    case Queued = 'queued';
    case Invoking = 'invoking';
    case Downloading = 'downloading';
    case Parsing = 'parsing';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Partial,
            self::Failed,
            self::Cancelled,
        ], true);
    }

    public function isActive(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * Estados que mantienen un run en curso. Las consultas de concurrencia
     * usan esta lista para impedir dos runs activos de la misma empresa.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isActive()),
        ));
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return match ($this) {
            self::Queued => in_array($next, [self::Invoking, self::Failed, self::Cancelled], true),
            self::Invoking => in_array($next, [self::Downloading, self::Failed, self::Cancelled], true),
            self::Downloading => in_array($next, [self::Parsing, self::Partial, self::Failed, self::Cancelled], true),
            self::Parsing => in_array($next, [self::Completed, self::Partial, self::Failed, self::Cancelled], true),
            self::Completed,
            self::Partial,
            self::Failed,
            self::Cancelled => false,
        };
    }
}
