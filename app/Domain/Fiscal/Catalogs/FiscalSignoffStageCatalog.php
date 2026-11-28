<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Catalogs;

/**
 * Etapas fiscales que requieren validación del contador.
 *
 * La huella incluye el contenido mostrado para que cualquier cambio de alcance
 * vuelva a dejar la etapa pendiente sin borrar las decisiones anteriores.
 */
final class FiscalSignoffStageCatalog
{
    /**
     * @return list<array{key: string, version: int, title: string, summary: string, references: list<string>, source_files: list<array{id: string, label: string, language: string, description: string}>}>
     */
    public static function all(): array
    {
        /** @var list<array{key: string, version: int, title: string, summary: string, references: list<string>, source_files: list<array{id: string, label: string, language: string, description: string}>}> $stages */
        $stages = [
            [
                'key' => 'iva-and-retention-catalogs',
                'version' => 1,
                'title' => 'Catálogos de tarifas y retenciones',
                'summary' => 'Validar códigos, porcentajes y períodos de vigencia de IVA y retenciones frente a las tablas ATS y normativa aplicable.',
                'references' => ['app/Domain/Fiscal/Catalogs/FiscalCatalogData.php', 'Ficha Técnica ATS, tablas de IVA y retenciones'],
            ],
            [
                'key' => 'expense-categories',
                'version' => 1,
                'title' => 'Categorías y deducibilidad',
                'summary' => 'Validar el árbol de categorías y las banderas de deducibilidad de gastos.',
                'references' => ['app/Domain/Classification/Catalogs/ExpenseCategoryData.php'],
            ],
            [
                'key' => 'form104-proportionality',
                'version' => 1,
                'title' => 'Proporcionalidad y crédito tributario',
                'summary' => 'Validar la fórmula del factor de proporcionalidad del Formulario 104 y su aplicación al IVA de compras.',
                'references' => ['app/Domain/Fiscal/Services/Proportionality.php', 'docs/02-implementation-plan.md §7.2'],
            ],
            [
                'key' => 'fiscal-risk-rules',
                'version' => 1,
                'title' => 'Reglas y severidades de hallazgos fiscales',
                'summary' => 'Validar los códigos, severidades y condiciones de resolución automática de hallazgos.',
                'references' => ['app/Domain/Fiscal/Enums/FiscalRiskCode.php', 'app/Domain/Fiscal/Services/FiscalRiskDetector.php'],
            ],
            [
                'key' => 'form104-official-lines',
                'version' => 2,
                'title' => 'Casilleros oficiales del Formulario 104',
                'summary' => 'Validar etiquetas, estructura y vigencia de los casilleros frente al formulario oficial.',
                'references' => ['app/Domain/Fiscal/Catalogs/Form104OfficialForm.php', 'Resolución NAC-DGERCGC16-00000210'],
            ],
            [
                'key' => 'period-aggregate-rate-resolution',
                'version' => 3,
                'title' => 'Resolución de tarifas en agregados',
                'summary' => 'Validar que la tarifa se resuelva por codigoPorcentaje y catálogo vigente cuando el totalImpuesto no publica tarifa.',
                'references' => ['app/Domain/Fiscal/Actions/ComputePeriodAggregates.php', 'Ficha Técnica del comprobante electrónico SRI'],
            ],
            [
                'key' => 'form104-complete-scope',
                'version' => 3,
                'title' => 'Alcance completo del Formulario 104',
                'summary' => 'Validar el mapeo de casilleros, naturaleza IVA, notas de crédito/débito, arrastre y datos manuales.',
                'references' => ['docs/02-implementation-plan.md §7.2', 'Guía Formulario Impuesto al Valor Agregado IVA'],
            ],
            [
                'key' => 'ats-iva-nature-mapping',
                'version' => 1,
                'title' => 'Naturaleza IVA por código ATS',
                'summary' => 'Validar la relación entre codigoPorcentaje y naturaleza fiscal. El derecho a crédito, activo fijo y asignaciones mixtas requieren información explícita y no se inferirán del código.',
                'references' => ['Ficha Técnica ATS, tablas codificadas y sección de compras', 'app/Domain/Fiscal/Catalogs/FiscalCatalogData.php'],
            ],
            [
                'key' => 'ats-retention-mapping',
                'version' => 1,
                'title' => 'Retenciones IVA del Formulario 104',
                'summary' => 'Validar los códigos ATS de retención IVA, sus vigencias y la correspondencia con los casilleros 721–731.',
                'references' => ['Ficha Técnica ATS, tabla 11', 'app/Domain/Fiscal/Catalogs/FiscalCatalogData.php'],
            ],
        ];

        foreach ($stages as &$stage) {
            $stage['source_files'] = FiscalSignoffSourceCatalog::metadataForStage($stage['key']);
        }
        unset($stage);

        return $stages;
    }

    /**
     * @return array{key: string, version: int, title: string, summary: string, references: list<string>, source_files: list<array{id: string, label: string, language: string, description: string}>, fingerprint: string}|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $stage) {
            if ($stage['key'] === $key) {
                return [...$stage, 'fingerprint' => self::fingerprint($stage)];
            }
        }

        return null;
    }

    /**
     * @param  array{key: string, version: int, title: string, summary: string, references: list<string>}  $stage
     */
    public static function fingerprint(array $stage): string
    {
        $fingerprintData = [
            'key' => $stage['key'],
            'version' => $stage['version'],
            'title' => $stage['title'],
            'summary' => $stage['summary'],
            'references' => $stage['references'],
        ];

        return hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
