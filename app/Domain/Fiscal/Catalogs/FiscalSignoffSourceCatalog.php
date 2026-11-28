<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Catalogs;

/**
 * Archivos fuente que pueden abrirse desde el flujo de validación contable.
 *
 * El allowlist vive aquí para que el frontend nunca envíe rutas del sistema:
 * solo recibe un identificador estable y el backend resuelve la ruta real.
 */
final class FiscalSignoffSourceCatalog
{
    /**
     * @return list<array{id: string, path: string, label: string, language: string, description: string}>
     */
    public static function forStage(string $stageKey): array
    {
        return match ($stageKey) {
            'iva-and-retention-catalogs', 'ats-iva-nature-mapping', 'ats-retention-mapping' => [
                self::file('fiscal-catalog-data', 'app/Domain/Fiscal/Catalogs/FiscalCatalogData.php', 'FiscalCatalogData.php', 'php', 'Catálogo de tarifas, retenciones y vigencias.'),
            ],
            'expense-categories' => [
                self::file('expense-category-data', 'app/Domain/Classification/Catalogs/ExpenseCategoryData.php', 'ExpenseCategoryData.php', 'php', 'Árbol de categorías y deducibilidad.'),
            ],
            'form104-proportionality' => [
                self::file('proportionality', 'app/Domain/Fiscal/Services/Proportionality.php', 'Proportionality.php', 'php', 'Cálculo del factor de proporcionalidad.'),
                self::file('implementation-plan', 'docs/02-implementation-plan.md', '02-implementation-plan.md', 'markdown', 'Alcance y notas de implementación del Formulario 104.'),
            ],
            'fiscal-risk-rules' => [
                self::file('fiscal-risk-code', 'app/Domain/Fiscal/Enums/FiscalRiskCode.php', 'FiscalRiskCode.php', 'php', 'Códigos y severidades de hallazgos fiscales.'),
                self::file('fiscal-risk-detector', 'app/Domain/Fiscal/Services/FiscalRiskDetector.php', 'FiscalRiskDetector.php', 'php', 'Detector de hallazgos fiscales.'),
            ],
            'form104-official-lines' => [
                self::file('form104-official-form', 'app/Domain/Fiscal/Catalogs/Form104OfficialForm.php', 'Form104OfficialForm.php', 'php', 'Estructura oficial del Formulario 104.'),
            ],
            'period-aggregate-rate-resolution' => [
                self::file('compute-period-aggregates', 'app/Domain/Fiscal/Actions/ComputePeriodAggregates.php', 'ComputePeriodAggregates.php', 'php', 'Agregados por período y resolución de tarifa.'),
            ],
            'form104-complete-scope' => [
                self::file('build-form104-draft', 'app/Domain/Fiscal/Actions/BuildForm104Draft.php', 'BuildForm104Draft.php', 'php', 'Borrador del Formulario 104.'),
                self::file('form104-official-form', 'app/Domain/Fiscal/Catalogs/Form104OfficialForm.php', 'Form104OfficialForm.php', 'php', 'Estructura oficial del Formulario 104.'),
                self::file('implementation-plan', 'docs/02-implementation-plan.md', '02-implementation-plan.md', 'markdown', 'Alcance completo del Formulario 104.'),
            ],
            default => [],
        };
    }

    /**
     * @return array{id: string, path: string, label: string, language: string, description: string}|null
     */
    public static function find(string $stageKey, string $fileId): ?array
    {
        foreach (self::forStage($stageKey) as $file) {
            if ($file['id'] === $fileId) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Metadatos públicos de los archivos permitidos para una etapa, sin ruta real.
     *
     * @return list<array{id: string, label: string, language: string, description: string}>
     */
    public static function metadataForStage(string $stageKey): array
    {
        return array_map(
            static fn (array $file): array => [
                'id' => $file['id'],
                'label' => $file['label'],
                'language' => $file['language'],
                'description' => $file['description'],
            ],
            self::forStage($stageKey),
        );
    }

    /**
     * @return array{id: string, path: string, label: string, language: string, description: string}
     */
    private static function file(string $id, string $path, string $label, string $language, string $description): array
    {
        return [
            'id' => $id,
            'path' => $path,
            'label' => $label,
            'language' => $language,
            'description' => $description,
        ];
    }
}
