<?php

declare(strict_types=1);

namespace App\Domain\Sri\Enums;

/**
 * Estado normalizado de una consulta de autorización al SRI.
 *
 * El SRI devuelve códigos como AUTORIZADO, RECHAZADO o PPR; el dominio usa
 * este enum para no depender del vocabulario cambiante del WSDL.
 */
enum AuthorizationStatus: string
{
    case Authorized = 'authorized';
    case Rejected = 'rejected';
    case Processing = 'processing';
    case NotFound = 'not_found';
    case Unknown = 'unknown';

    public function isFinal(): bool
    {
        return in_array($this, [self::Authorized, self::Rejected], true);
    }

    public function isRetryable(): bool
    {
        return in_array($this, [self::Processing, self::Unknown], true);
    }
}
