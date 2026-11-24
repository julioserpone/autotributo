<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\DetectFiscalRisks;
use App\Domain\Fiscal\Enums\FiscalRiskStatus;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\Fiscal\Services\FiscalRiskDetector;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saneamiento de hallazgos (F3-06b).
 *
 * Hace tres cosas, en orden, y ninguna destruye datos del usuario:
 *
 * 1. `--purge-synthetic`: borra hallazgos cuyo `source_id` apunta a un
 *    comprobante que ya no existe (datos de demostración que heredaban la
 *    numeración de los comprobantes reales y "se pegaban" a documentos
 *    sincronizados). Los hallazgos abiertos de esos comprobantes los vuelve a
 *    crear la detección con datos veraces.
 * 2. Cierra los hallazgos de totales inconsistentes que la regla anterior
 *    produjo por error: todo comprobante con `totals_mismatch` y la fórmula
 *    corregida da cuadre (`subtotal + impuestos + ICE + IRBPNR + propina`).
 * 3. Vuelve a ejecutar la detección para dejar descripciones y metadatos al
 *    día y resolver los riesgos que ya no existen.
 *
 * Uso: `php artisan fiscal:reconcile-findings {--dry-run} {--purge-synthetic}`
 */
final class ReconcileFiscalFindingsCommand extends Command
{
    protected $signature = 'fiscal:reconcile-findings
        {--dry-run : Muestra lo que haría sin escribir nada}
        {--purge-synthetic : Borra los hallazgos cuyo comprobante de origen ya no existe}
        {--company= : Limita el saneamiento a una empresa (public_id)}';

    protected $description = 'Sanea hallazgos previos: purga huérfanos, cierra falsos totales y re-detecta';

    public function handle(DetectFiscalRisks $detect, FiscalRiskDetector $detector, TenantContext $context): int
    {
        if (! $context->isInitialized()) {
            $context->initializeDefault();
        }

        $dryRun = (bool) $this->option('dry-run');

        $companies = $this->companies();

        if ($companies->isEmpty()) {
            $this->warn('No se encontraron empresas para analizar.');

            return self::SUCCESS;
        }

        $this->purgeSyntheticSignal($dryRun);
        $this->resolveFalseTotalsMismatch($companies, $dryRun);

        foreach ($companies as $company) {
            if ($dryRun) {
                $this->line(sprintf('[dry-run] %s: se re-detectan los riesgos.', $company->legal_name));

                continue;
            }

            $result = $detect->handle($company);

            $this->info(sprintf(
                '%s: %d creados, %d actualizados, %d resueltos.',
                $company->legal_name,
                $result->created,
                $result->updated,
                $result->resolved,
            ));
        }

        $this->reportTotalsMismatch($detector, $companies);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Company>
     */
    private function companies(): Collection
    {
        $publicId = $this->option('company');

        return is_string($publicId) && $publicId !== ''
            ? Company::query()->where('public_id', $publicId)->get()
            : Company::query()->orderBy('id')->get();
    }

    /**
     * Detecta hallazgos apuntando a comprobantes inexistentes (`source_id` sin
     * fila). Son datos de demostración heredados; su borrado no afecta a
     * comprobantes ni a decisiones reales, solo a registros cuya fuente ya no
     * existe.
     */
    private function purgeSyntheticSignal(bool $dryRun): void
    {
        $orphans = FiscalFinding::query()
            ->where('source_type', 'fiscal_document')
            ->whereNotIn('source_id', DB::table('fiscal_documents')->select('id'))
            ->get();

        if ($orphans->isEmpty()) {
            return;
        }

        if (! $this->option('purge-synthetic')) {
            $this->warn(sprintf(
                '%d hallazgos apuntan a comprobantes inexistentes (datos de demostración). Usa --purge-synthetic para eliminarlos.',
                $orphans->count(),
            ));

            return;
        }

        if ($dryRun) {
            $this->line(sprintf('[dry-run] se eliminarían %d hallazgos huérfanos (ids %s).', $orphans->count(), $orphans->pluck('id')->join(',')));

            return;
        }

        FiscalFinding::query()->whereKey($orphans->pluck('id'))->delete();

        $this->info(sprintf('Eliminados %d hallazgos huérfanos de demostración.', $orphans->count()));
    }

    /**
     * Cierra los hallazgos abiertos de `TOTALS_MISMATCH` de comprobantes que
     * con la fórmula oficial del SRI sí cuadran. El motivo queda en la nota
     * para que la traza del cambio sea visible en la UI.
     */
    /**
     * @param  Collection<int, Company>  $companies
     */
    private function resolveFalseTotalsMismatch(Collection $companies, bool $dryRun): void
    {
        $candidates = FiscalFinding::query()
            ->whereIn('company_id', $companies->pluck('id'))
            ->where('code', 'TOTALS_MISMATCH')
            ->where('status', FiscalRiskStatus::Open->value)
            ->with('sourceDocument')
            ->get();

        $resolved = 0;

        foreach ($candidates as $finding) {
            $document = $finding->sourceDocument;

            if ($document === null || $document->document_type === SriDocumentType::Withholding) {
                continue;
            }

            $expected = (int) $document->subtotal_cents
                + (int) $document->tax_cents
                + (int) $document->ice_cents
                + (int) $document->irbpnr_cents
                + (int) $document->tip_cents;

            if ($expected !== (int) $document->total_cents) {
                continue;
            }

            $resolved++;

            if ($dryRun) {
                $this->line(sprintf('[dry-run] se cerraría el hallazgo %d (%s): el total sí cuadra.', $finding->id, $document->access_key));

                continue;
            }

            $document->totals_mismatch = false;
            $document->save();

            $metadata = is_array($finding->metadata) ? $finding->metadata : [];
            $metadata['note'] = 'Cerrado automáticamente: el total del comprobante cuadra con la fórmula oficial del SRI (totalSinImpuestos + impuestos + propina); el descuento ya venía restado.';

            $finding->status = FiscalRiskStatus::Resolved;
            $finding->resolved_at = now();
            $finding->resolved_by = null;
            $finding->metadata = $metadata;
            $finding->save();
        }

        if ($resolved > 0 && ! $dryRun) {
            $this->info(sprintf('Cerrados %d hallazgos de totales que ahora cuadran.', $resolved));
        }
    }

    /**
     * @param  Collection<int, Company>  $companies
     */
    private function reportTotalsMismatch(FiscalRiskDetector $detector, Collection $companies): void
    {
        foreach ($companies as $company) {
            $documents = $detector->totalsMismatch($company);

            if ($documents->isEmpty()) {
                continue;
            }

            $this->warn(sprintf('%s: %d comprobantes con totales realmente inconsistentes.', $company->legal_name, $documents->count()));
        }
    }
}
