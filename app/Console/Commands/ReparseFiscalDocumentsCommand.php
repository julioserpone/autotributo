<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Sri\Contracts\SriFiscalDocumentParser;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Reinterpreta el XML guardado de comprobantes puntuales.
 *
 * Sirve para recalcular totales cuando cambió el parser o la fórmula de cuadre
 * y no se quiere volver a sincronizar con el SRI (que volvería a descargar
 * cientos de archivos). El XML se lee del almacenamiento local; si ya no está,
 * el comprobante se informa y no se modifica.
 *
 * Uso: `php artisan fiscal:reparse-documents {access_key*} {--dry-run}`
 */
final class ReparseFiscalDocumentsCommand extends Command
{
    protected $signature = 'fiscal:reparse-documents
        {access_keys* : Claves de acceso de los comprobantes}
        {--dry-run : Muestra el resultado sin guardar}';

    protected $description = 'Reinterpreta el XML guardado de comprobantes y actualiza sus totales';

    public function handle(SriFiscalDocumentParser $parser, TenantContext $context): int
    {
        if (! $context->isInitialized()) {
            $context->initializeDefault();
        }

        /** @var list<string> $keys */
        $keys = $this->argument('access_keys');
        $dryRun = (bool) $this->option('dry-run');

        foreach ($keys as $key) {
            $document = FiscalDocument::query()->where('access_key', $key)->first();

            if (! $document instanceof FiscalDocument) {
                $this->warn("{$key}: no existe en este despliegue.");

                continue;
            }

            $xml = $this->xmlFor($document);

            if ($xml === null) {
                $this->warn("{$key}: el XML ya no está en el almacenamiento; no se puede reinterpretar.");

                continue;
            }

            $data = $parser->parse($xml);

            $this->line(sprintf(
                '%s: base %d → %d, impuestos %d → %d, ICE %d → %d, total %d → %d (esperado %d).',
                $key,
                (int) $document->subtotal_cents,
                $data->subtotalCents,
                (int) $document->tax_cents,
                $data->taxCents,
                (int) $document->ice_cents,
                $data->iceCents,
                (int) $document->total_cents,
                $data->totalCents,
                $data->expectedTotalCents(),
            ));

            if ($dryRun) {
                $this->line('  [dry-run] sin cambios.');

                continue;
            }

            $document->subtotal_cents = $data->subtotalCents;
            $document->discount_cents = $data->discountCents;
            $document->tax_cents = $data->taxCents;
            $document->ice_cents = $data->iceCents;
            $document->irbpnr_cents = $data->irbpnrCents;
            $document->tip_cents = $data->tipCents;
            $document->total_cents = $data->totalCents;
            $document->totals_mismatch = $data->totalsMismatch();
            $document->save();

            $this->info('  actualizado.');
        }

        return self::SUCCESS;
    }

    /**
     * XML guardado del comprobante, en la misma clave S3 que `xml_path`.
     *
     * No se vuelve a pedir al SRI a propósito: reinterpretar un comprobante no
     * debe disparar una descarga. Si el XML ya no está, se avisa y no se toca.
     */
    private function xmlFor(FiscalDocument $document): ?string
    {
        $path = $document->xml_path;

        if (! is_string($path) || $path === '') {
            return null;
        }

        $disk = (string) config('sri.robot.output_disk', 'sri');

        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        return (string) Storage::disk($disk)->get($path);
    }
}
