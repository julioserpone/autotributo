<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use InvalidArgumentException;

final class InvalidTenancyMode extends InvalidArgumentException
{
    public static function forMode(string $mode): self
    {
        return new self("TENANCY_MODE [{$mode}] no es válido. Usa `single` o `multi`.");
    }
}
