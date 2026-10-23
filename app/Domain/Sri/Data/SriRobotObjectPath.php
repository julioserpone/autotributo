<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use App\Domain\Sri\Enums\SriDocumentType;
use InvalidArgumentException;

/**
 * Claves lógicas de los objetos que escribe `sri-txt-robot` en S3.
 *
 * El robot usa la jerarquía `{ambiente}/{ruc}/{periodo}`. El prefijo opcional
 * del bucket se configura en el disco `sri` de Laravel, no en esta clase, para
 * que las claves persistidas sean estables y legibles.
 */
final readonly class SriRobotObjectPath
{
    public string $environment;

    public string $ruc;

    public string $period;

    public function __construct(string $environment, string $ruc, string $period)
    {
        $environment = strtolower(trim($environment));
        $ruc = trim($ruc);
        $period = trim($period);

        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $environment) !== 1) {
            throw new InvalidArgumentException("Ambiente de robot inválido: {$environment}.");
        }

        if (preg_match('/^\d{10}(\d{3})?$/', $ruc) !== 1) {
            throw new InvalidArgumentException("RUC inválido: {$ruc}.");
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])(-(0[1-9]|[12]\d|3[01]))?$/', $period) !== 1) {
            throw new InvalidArgumentException("Período inválido: {$period}.");
        }

        $this->environment = $environment;
        $this->ruc = $ruc;
        $this->period = $period;
    }

    public function directory(): string
    {
        return "{$this->environment}/{$this->ruc}/{$this->period}";
    }

    public function prefix(): string
    {
        return $this->directory().'/';
    }

    public function textReport(SriDocumentType $type): string
    {
        return "{$this->directory()}/{$type->slug()}-recibidas-{$this->period}.txt";
    }

    public function accessKeys(SriDocumentType $type): string
    {
        return "{$this->directory()}/{$type->slug()}-recibidas-{$this->period}-claves.json";
    }

    public function manifest(): string
    {
        return "{$this->directory()}/manifest.json";
    }
}
