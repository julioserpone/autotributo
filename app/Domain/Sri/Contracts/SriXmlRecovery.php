<?php

declare(strict_types=1);

namespace App\Domain\Sri\Contracts;

use App\Domain\Sri\Data\SriXmlLocation;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Sri\Models\SriSyncRunFile;

/**
 * Recupera los XML de un run desde el repositorio S3 del robot.
 *
 * Existe para que el flujo no dependa del túnel del callback: si el robot ya
 * publicó los comprobantes en S3, la aplicación los lee de ahí aunque el
 * callback no haya llegado (URL caída, firma rechazada o evento perdido).
 *
 * La recuperación solo opera sobre períodos verificados: el `manifest.json`
 * del período debe coincidir en ambiente, RUC y período con el run.
 */
interface SriXmlRecovery
{
    /**
     * Verifica el período en S3 y registra en `sri_sync_run_files` los objetos
     * encontrados, con su checksum y su estado (`available`, `missing` o el
     * que corresponda). No descarga los XML completos.
     */
    public function discover(SriSyncRun $run): bool;

    /**
     * Localiza y lee el archivo registrado de un comprobante, solo si el
     * período del run está verificado en S3.
     */
    public function find(SriSyncRun $run, SriSyncRunFile $file): ?SriXmlLocation;

    /**
     * Devuelve el XML completo de una ubicación ya localizada. Se lee dos
     * veces a propósito: primero el checksum, después el contenido, de modo
     * que lo persistido se calcule sobre los mismos bytes que se interpretan.
     */
    public function contents(SriXmlLocation $location): string;

    /**
     * Registra el resultado de intentar recuperar un comprobante: `available`
     * con su checksum, o `missing` con el motivo seguro.
     *
     * @param  array<string, int>  $totals
     */
    public function mark(SriSyncRun $run, SriSyncRunFile $file, ?SriXmlLocation $location, array $totals): void;
}
