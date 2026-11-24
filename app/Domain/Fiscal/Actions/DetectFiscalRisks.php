<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Data\FiscalRiskDetectionResult;
use App\Domain\Fiscal\Enums\FiscalRiskCode;
use App\Domain\Fiscal\Enums\FiscalRiskStatus;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\Fiscal\Services\FiscalRiskDetector;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;

/**
 * Detecta riesgos fiscales sobre los comprobantes de una empresa y persiste
 * los hallazgos (`FiscalFinding`) de forma idempotente.
 *
 * Códigos auto-resueltos (cuando el riesgo desaparece): `TOTALS_MISMATCH`
 * y `DUPLICATE_SEQUENTIAL`. El resto requiere acción humana.
 */
final readonly class DetectFiscalRisks
{
    private const AUTO_RESOLVED = [
        FiscalRiskCode::TotalsMismatch,
        FiscalRiskCode::DuplicateSequential,
    ];

    public function __construct(
        private FiscalRiskDetector $detector,
        private TenantContext $tenantContext,
    ) {}

    public function handle(
        Company $company,
        ?\DateTimeInterface $periodStart = null,
        ?\DateTimeInterface $periodEnd = null,
    ): FiscalRiskDetectionResult {
        $tenant = Tenant::query()->findOrFail($company->tenant_id);

        return $this->tenantContext->run($tenant, function () use ($company): FiscalRiskDetectionResult {
            $created = 0;
            $updated = 0;
            $resolved = 0;
            $findings = [];

            $detections = [
                FiscalRiskCode::TotalsMismatch->value => [$this->detector->totalsMismatch($company), FiscalRiskCode::TotalsMismatch],
                FiscalRiskCode::InvalidRuc->value => [$this->detector->invalidRuc($company), FiscalRiskCode::InvalidRuc],
                FiscalRiskCode::GhostCompany->value => [$this->detector->ghostCompany($company), FiscalRiskCode::GhostCompany],
                FiscalRiskCode::DuplicateSequential->value => [$this->detector->duplicateSequential($company), FiscalRiskCode::DuplicateSequential],
            ];

            $detectedSourceIds = [];

            foreach ($detections as $codeValue => $pair) {
                [$documents, $code] = $pair;
                $ids = [];
                foreach ($documents as $document) {
                    $ids[] = $document->id;
                    [$finding, $wasCreated, $wasUpdated] = $this->upsertFinding(
                        $company,
                        $code,
                        $document,
                    );

                    if ($wasCreated) {
                        $created++;
                    } elseif ($wasUpdated) {
                        $updated++;
                    }

                    $findings[] = $finding;
                }
                $detectedSourceIds[$code->value] = $ids;
            }

            foreach (self::AUTO_RESOLVED as $code) {
                $openFindings = FiscalFinding::query()
                    ->where('company_id', $company->id)
                    ->where('code', $code->value)
                    ->where('status', FiscalRiskStatus::Open->value)
                    ->get();

                foreach ($openFindings as $finding) {
                    if (in_array($finding->source_id, $detectedSourceIds[$code->value] ?? [], true)) {
                        continue;
                    }

                    $finding->status = FiscalRiskStatus::Resolved;
                    $finding->resolved_at = now();
                    $finding->resolved_by = null;
                    $finding->save();
                    $resolved++;
                }
            }

            return new FiscalRiskDetectionResult($created, $updated, $resolved, $findings);
        });
    }

    /**
     * @return array{0: FiscalFinding, 1: bool, 2: bool}
     */
    private function upsertFinding(
        Company $company,
        FiscalRiskCode $code,
        FiscalDocument $document,
    ): array {
        $existing = FiscalFinding::query()
            ->where('company_id', $company->id)
            ->where('code', $code->value)
            ->where('source_type', 'fiscal_document')
            ->where('source_id', $document->id)
            ->where('status', FiscalRiskStatus::Open->value)
            ->first();

        $severity = FiscalRiskDetector::severityFor($code);
        $title = FiscalRiskDetector::titleFor($code);
        $description = $this->descriptionFor($code, $document);

        if ($existing instanceof FiscalFinding) {
            $changed = false;

            if ($existing->severity !== $severity) {
                $existing->severity = $severity;
                $changed = true;
            }

            if ($existing->title !== $title) {
                $existing->title = $title;
                $changed = true;
            }

            if ($existing->description !== $description) {
                $existing->description = $description;
                $changed = true;
            }

            if ($changed) {
                $existing->save();
            }

            return [$existing, false, $changed];
        }

        $finding = FiscalFinding::query()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'code' => $code->value,
            'severity' => $severity->value,
            'title' => $title,
            'description' => $description,
            'source_type' => 'fiscal_document',
            'source_id' => $document->id,
            'metadata' => $this->metadataFor($code, $document),
            'status' => FiscalRiskStatus::Open->value,
        ]);

        return [$finding, true, false];
    }

    private function descriptionFor(FiscalRiskCode $code, FiscalDocument $document): string
    {
        return match ($code) {
            FiscalRiskCode::TotalsMismatch => sprintf(
                'El comprobante %s del emisor %s presenta totales inconsistentes.',
                $document->access_key,
                $document->issuer_name,
            ),
            FiscalRiskCode::InvalidRuc => sprintf(
                'El RUC del emisor %s (%s) no es válido.',
                $document->issuer_name,
                $document->issuer_ruc,
            ),
            FiscalRiskCode::GhostCompany => sprintf(
                'El emisor %s (%s) figura en la lista de empresas fantasma del SRI.',
                $document->issuer_name,
                $document->issuer_ruc,
            ),
            FiscalRiskCode::DuplicateSequential => sprintf(
                'El comprobante %s comparte numeración con otro comprobante del mismo emisor.',
                $document->access_key,
            ),
            default => 'Hallazgo fiscal detectado.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataFor(FiscalRiskCode $code, FiscalDocument $document): array
    {
        return [
            'access_key' => $document->access_key,
            'document_number' => sprintf(
                '%s-%s-%s',
                $document->establishment,
                $document->emission_point,
                $document->sequential,
            ),
            'issue_date' => $document->issue_date?->toDateString(),
            'subtotal_cents' => $document->subtotal_cents,
            'tax_cents' => $document->tax_cents,
            'total_cents' => $document->total_cents,
            'totals_mismatch' => (bool) $document->totals_mismatch,
        ];
    }
}
