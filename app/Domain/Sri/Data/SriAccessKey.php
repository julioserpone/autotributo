<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use App\Domain\Sri\Enums\SriDocumentType;
use DateTimeImmutable;
use InvalidArgumentException;
use JulioSerpone\SriComprobantes\AccessKey\AccessKeyGenerator;

/**
 * Clave de acceso del SRI (49 dígitos) con su estructura decodificada.
 *
 * La estructura y el dígito verificador los define la ficha técnica y los
 * valida `julioserpone/sri-comprobantes-php`; este objeto solo decodifica los
 * campos que el dominio necesita (tipo, RUC emisor, ambiente, período) y
 * traduce el booleano del paquete a una excepción con motivo.
 *
 * Estructura: `ddmmaaaa cc rrrrrrrrrrrrr t sssssssss d c` — fecha (8), tipo
 * (2), RUC (13), ambiente (1), serie (6), secuencial (9), código numérico (8),
 * verificador (1) y tipo de emisión (1).
 */
final readonly class SriAccessKey
{
    public string $value;

    public DateTimeImmutable $issuedAt;

    public SriDocumentType $documentType;

    public string $issuerRuc;

    public string $environment;

    public string $establishment;

    public string $emissionPoint;

    public string $sequential;

    public string $checkDigit;

    public string $emissionType;

    public function __construct(string $accessKey)
    {
        $normalized = preg_replace('/\s+/', '', $accessKey) ?? '';

        if (preg_match('/^\d{49}$/', $normalized) !== 1) {
            throw new InvalidArgumentException('La clave de acceso debe contener exactamente 49 dígitos.');
        }

        $type = SriDocumentType::tryFrom(substr($normalized, 8, 2));

        if ($type === null) {
            throw new InvalidArgumentException(
                'La clave de acceso no corresponde a un tipo de comprobante soportado: '
                .substr($normalized, 8, 2).'.',
            );
        }

        $date = DateTimeImmutable::createFromFormat('!dmY', substr($normalized, 0, 8));

        // `createFromFormat` acepta fechas imposibles (31/02) y las desplaza;
        // se compara la cadena formateada para detectar ese desplazamiento.
        if ($date === false || $date->format('dmY') !== substr($normalized, 0, 8)) {
            throw new InvalidArgumentException('La clave de acceso no contiene una fecha de emisión válida.');
        }

        $environment = substr($normalized, 23, 1);

        if (! in_array($environment, ['1', '2'], true)) {
            throw new InvalidArgumentException("Ambiente inválido en la clave de acceso: {$environment}.");
        }

        if (! (new AccessKeyGenerator)->isValid($normalized)) {
            throw new InvalidArgumentException('El dígito verificador de la clave de acceso no es válido.');
        }

        $this->value = $normalized;
        $this->issuedAt = $date;
        $this->documentType = $type;
        $this->issuerRuc = substr($normalized, 10, 13);
        $this->environment = $environment;
        $this->establishment = substr($normalized, 24, 3);
        $this->emissionPoint = substr($normalized, 27, 3);
        $this->sequential = substr($normalized, 30, 9);
        $this->checkDigit = substr($normalized, 48, 1);
        $this->emissionType = substr($normalized, 47, 1);
    }

    public function period(): string
    {
        return $this->issuedAt->format('Y-m');
    }

    public function isProduction(): bool
    {
        return $this->environment === '2';
    }

    /**
     * Dígito verificador de los primeros 48 dígitos.
     *
     * Delega en el paquete para no mantener dos implementaciones del módulo 11.
     */
    public static function checkDigitFor(string $accessKey): string
    {
        $digits = substr(preg_replace('/\s+/', '', $accessKey) ?? '', 0, 48);

        if (preg_match('/^\d{48}$/', $digits) !== 1) {
            throw new InvalidArgumentException('Se necesitan 48 dígitos para calcular el verificador.');
        }

        return (string) AccessKeyGenerator::module11($digits);
    }
}
