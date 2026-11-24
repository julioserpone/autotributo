<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Services;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Enums\FiscalRiskCode;
use App\Domain\Fiscal\Enums\FiscalRiskSeverity;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\Fiscal\Models\GhostCompany;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Shared\Values\Ruc;
use Illuminate\Support\Collection;

/**
 * Detectores de riesgos fiscales sobre los comprobantes de una empresa.
 *
 * Cada método devuelve la lista de `FiscalDocument` que presentan el riesgo.
 * La persistencia y el upsert de `FiscalFinding` la orquesta `DetectFiscalRisks`.
 */
final class FiscalRiskDetector
{
    /**
     * @return Collection<int, FiscalDocument>
     */
    public function totalsMismatch(Company $company): Collection
    {
        return FiscalDocument::query()
            ->where('company_id', $company->id)
            ->where('totals_mismatch', true)
            ->get();
    }

    /**
     * @return Collection<int, FiscalDocument>
     */
    public function invalidRuc(Company $company): Collection
    {
        return FiscalDocument::query()
            ->where('company_id', $company->id)
            ->get()
            ->filter(static fn (FiscalDocument $document): bool => Ruc::fromImported((string) $document->issuer_ruc) === null);
    }

    /**
     * @return Collection<int, FiscalDocument>
     */
    public function ghostCompany(Company $company): Collection
    {
        $ghostRucs = GhostCompany::query()->pluck('ruc')->all();

        if ($ghostRucs === []) {
            return collect();
        }

        return FiscalDocument::query()
            ->where('company_id', $company->id)
            ->whereIn('issuer_ruc', $ghostRucs)
            ->get();
    }

    /**
     * @return Collection<int, FiscalDocument>
     */
    public function duplicateSequential(Company $company): Collection
    {
        $documents = FiscalDocument::query()
            ->where('company_id', $company->id)
            ->get();

        $groups = $documents->groupBy(
            static fn (FiscalDocument $document): string => implode('|', [
                (string) $document->issuer_ruc,
                (string) $document->establishment,
                (string) $document->emission_point,
                (string) $document->sequential,
            ]),
        );

        $duplicates = collect();

        foreach ($groups as $group) {
            if ($group->count() > 1) {
                foreach ($group as $document) {
                    $duplicates->push($document);
                }
            }
        }

        return $duplicates;
    }

    /**
     * Severidad sugerida por código de riesgo.
     */
    public static function severityFor(FiscalRiskCode $code): FiscalRiskSeverity
    {
        return match ($code) {
            FiscalRiskCode::TotalsMismatch, FiscalRiskCode::GhostCompany => FiscalRiskSeverity::Critical,
            default => FiscalRiskSeverity::Warning,
        };
    }

    /**
     * Título legible para la UI.
     */
    public static function titleFor(FiscalRiskCode $code): string
    {
        return match ($code) {
            FiscalRiskCode::TotalsMismatch => 'Totales inconsistentes',
            FiscalRiskCode::InvalidRuc => 'RUC del emisor inválido',
            FiscalRiskCode::DuplicateSequential => 'Comprobante duplicado',
            FiscalRiskCode::GhostCompany => 'Empresa fantasma del SRI',
            default => $code->value,
        };
    }
}
