<?php

declare(strict_types=1);

namespace App\Domain\Sri\Exceptions;

use RuntimeException;

/**
 * Se superó el número de invocaciones permitidas por minuto para un RUC.
 *
 * El mensaje es seguro para logs: no incluye el RUC ni credenciales. El
 * llamador puede usar `retryAfterSeconds` para responder 429 o reintentar.
 */
final class SriRateLimitExceeded extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(
            "Se alcanzó el límite de sincronizaciones por minuto. Reintente en {$retryAfterSeconds} segundos.",
        );
    }

    public static function retryAfter(int $seconds): self
    {
        return new self(max(1, $seconds));
    }
}
