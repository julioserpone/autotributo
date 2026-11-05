<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Sri\Contracts\SriRobotObjectStore;
use App\Domain\Sri\Contracts\SriXmlRecovery;
use App\Domain\Sri\Data\SriRobotObjectPath;
use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use Throwable;

/**
 * Recupera desde S3 un run cuyo callback no llegó.
 *
 * Sirve cuando la URL del callback estaba caída: el robot terminó y publicó
 * los archivos, pero no hubo evento que moviera el run. La recuperación solo
 * actúa si el período está verificado en el bucket y el robot ya dejó al menos
 * un TXT o un XML:
 *
 * - `invoking` → `downloading` → `parsing` cuando hay lote y manifiesto.
 * - Si el robot terminó sin comprobantes (`sin_datos`, sin fallos), el run se
 *   cierra en `failed` con `error_code=no_documents` y `totals.found = 0`:
 *   no es un fallo técnico, pero tampoco hay nada que importar.
 *
 * No importa comprobantes: deja el run listo para `ImportSriRun`.
 */
final readonly class RecoverSriRunFromS3
{
    public function __construct(
        private SriRobotObjectStore $store,
        private SriXmlRecovery $recovery,
        private ReconcileSriRun $reconcile,
        private string $environment,
    ) {}

    public function handle(SriSyncRun $run): SriSyncRun
    {
        // Un run en `parsing` ya tiene lote publicado; se deja tal cual.
        if (! in_array($run->status, [SriSyncRunStatus::Invoking, SriSyncRunStatus::Downloading], true)) {
            return $run;
        }

        $verified = $this->recovery->discover($run);

        if ($verified && $run->status === SriSyncRunStatus::Invoking) {
            $run->transitionTo(SriSyncRunStatus::Downloading);
        }

        if ($run->status !== SriSyncRunStatus::Downloading) {
            return $run->refresh();
        }

        // Reconciliar valida el manifiesto y el lote del período; da igual que
        // ya haya archivos registrados por la recuperación.
        $run = $this->reconcile->handle($run);

        // El robot terminó sin comprobantes: el run no tiene lote ni nada que
        // interpretar, así que se cierra con `no_documents`.
        if ($run->status === SriSyncRunStatus::Parsing && $this->manifestReportsNothing($run)) {
            return $this->closeEmpty($run);
        }

        // Con archivos el run ya tiene lote: sigue el flujo de importación.
        if ($this->hasFiles($run)) {
            return $run;
        }

        return $this->closeEmpty($run);
    }

    /**
     * El robot terminó el período sin comprobantes: no hay TXT que interpretar
     * ni XML que importar. El run se cierra con un error de negocio (no un
     * fallo técnico) para que no se quede activo para siempre.
     */
    private function closeEmpty(SriSyncRun $run): SriSyncRun
    {
        // Solo se cierra cuando el manifiesto del período dice que no hay
        // comprobantes; sin manifiesto, el robot aún no terminó y el run sigue.
        if (! $this->manifestReportsNothing($run)) {
            return $run;
        }

        $totals = $run->totals ?? [];
        $run->totals = [
            'found' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'from_s3' => 0,
            'from_sri' => 0,
            ...$totals,
        ];
        $run->error_code = 'no_documents';
        $run->error_message = 'El robot no encontró comprobantes recibidos para el período consultado.';
        $run->transitionTo(SriSyncRunStatus::Failed);

        return $run->refresh();
    }

    private function hasFiles(SriSyncRun $run): bool
    {
        return $run->files()
            ->whereIn('status', [SriSyncRunFileStatus::Available, SriSyncRunFileStatus::Processed])
            ->exists();
    }

    /**
     * Lee el manifiesto de cada período del run: solo se cierra como vacío
     * cuando el del último período es válido y no declara descargas. Un
     * manifiesto ausente o ilegible se considera «no terminado», no vacío.
     */
    private function manifestReportsNothing(SriSyncRun $run): bool
    {
        $ruc = (string) $run->company()->firstOrFail()->ruc;
        $periods = array_values(array_filter(
            array_map(static fn (mixed $period): string => (string) $period, $run->periods ?? []),
            static fn (string $period): bool => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1,
        ));
        $period = $periods === [] ? '' : (string) end($periods);

        if ($period === '') {
            return false;
        }

        $key = (new SriRobotObjectPath($this->environment, $ruc, $period))->manifest();

        try {
            if (! $this->store->exists($key)) {
                return false;
            }

            $data = json_decode($this->store->read($key), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($data)
                || strtolower((string) ($data['ambiente'] ?? '')) !== $this->environment
                || (string) ($data['ruc'] ?? '') !== $ruc
                || (string) ($data['periodo'] ?? '') !== $period) {
                return false;
            }

            $entries = $data['descargas'] ?? null;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return is_array($entries) && $entries === [];
    }
}
