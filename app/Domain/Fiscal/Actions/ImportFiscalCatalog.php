<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Actions;

use App\Domain\Fiscal\Catalogs\CsvCatalogReader;
use RuntimeException;

/**
 * Importa un catálogo fiscal versionado desde un CSV oficial.
 *
 * Normaliza las cabeceras del CSV al nombre de columna de la tabla, completa
 * lo que el modelo puede derivar (tal como hace `SyncFiscalCatalogs` con el
 * catálogo embebido) y delega el upsert idempotente. Un CSV con columnas que
 * no corresponden al catálogo se rechaza antes de escribir nada.
 *
 * Uso típico: `php artisan catalogs:import tax_rates storage/catalogs/iva.csv`.
 */
final readonly class ImportFiscalCatalog
{
    /**
     * Catálogos importables y columnas que admite cada uno.
     *
     * @var array<string, list<string>>
     */
    public const CATALOGS = [
        'tax_rates' => ['code', 'sri_percentage_code', 'rate', 'label', 'applies_to', 'valid_from', 'valid_to', 'legal_basis', 'source', 'published_at'],
        'retention_rates' => ['tax_type', 'code', 'rate', 'label', 'applies_to', 'valid_from', 'valid_to', 'legal_basis', 'source', 'published_at'],
        'form_lines' => ['form_code', 'line_code', 'section', 'label', 'data_type', 'is_required', 'display_order', 'valid_from', 'valid_to', 'source', 'published_at'],
        'personal_expense_caps' => ['fiscal_year', 'category_code', 'dependents', 'cap_cents', 'baskets', 'basic_food_basket_cents', 'valid_from', 'valid_to', 'source', 'published_at'],
    ];

    /**
     * Sinónimos que publica el SRI y que no son idénticos al nombre de columna.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'porcentaje' => 'rate',
        'tarifa' => 'rate',
        'codigo_porcentaje' => 'sri_percentage_code',
        'codigo' => 'code',
        'descripcion' => 'label',
        'detalle' => 'label',
        'vigencia_desde' => 'valid_from',
        'vigencia_hasta' => 'valid_to',
        'fecha_desde' => 'valid_from',
        'fecha_hasta' => 'valid_to',
        'anio' => 'fiscal_year',
        'ano' => 'fiscal_year',
        'cargas' => 'dependents',
        'tope_centavos' => 'cap_cents',
        'canastas' => 'baskets',
        'fuente' => 'source',
    ];

    public function __construct(
        private SyncFiscalCatalogs $sync,
        private CsvCatalogReader $reader = new CsvCatalogReader,
    ) {}

    /**
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function handle(string $catalog, string $csv, bool $dryRun = false): array
    {
        $allowed = self::CATALOGS[$catalog] ?? null;

        if ($allowed === null) {
            throw new RuntimeException(sprintf(
                'Catálogo desconocido: %s. Válidos: %s.',
                $catalog,
                implode(', ', array_keys(self::CATALOGS)),
            ));
        }

        $rows = [];

        foreach ($this->reader->read($csv) as $index => $row) {
            $normalized = $this->normalizeRow($catalog, $index, $row, $allowed);
            $rows[] = $this->withDerivedColumns($catalog, $normalized);
        }

        if ($rows === []) {
            throw new RuntimeException('El CSV no tiene filas de datos.');
        }

        return $this->sync->import($catalog, $rows, $dryRun);
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function normalizeRow(string $catalog, int $index, array $row, array $allowed): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = [];

        foreach ($row as $header => $value) {
            $column = self::ALIASES[$header] ?? $header;

            if (! in_array($column, $allowed, true)) {
                throw new RuntimeException(sprintf(
                    '%s: la fila %d trae la columna `%s`, que no corresponde a este catálogo.',
                    $catalog,
                    $index + 1,
                    $header,
                ));
            }

            $normalized[$column] = $value;
        }

        return $normalized;
    }

    /**
     * Columnas que el modelo puede completar solo: fuente, fecha de
     * publicación y los topes de gastos personales derivados de las canastas.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withDerivedColumns(string $catalog, array $row): array
    {
        $row['source'] ??= 'import:csv';
        $row['published_at'] ??= now()->toDateString();

        if ($catalog === 'personal_expense_caps'
            && ! isset($row['cap_cents'])
            && isset($row['baskets'], $row['basic_food_basket_cents'])
        ) {
            $row['cap_cents'] = (int) $row['baskets'] * (int) $row['basic_food_basket_cents'];
        }

        return $row;
    }
}
