<?php

declare(strict_types=1);

namespace Tests\Support\Sri;

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Data\SriRobotObjectPath;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Objetos de prueba con el mismo contrato que publica `sri-txt-robot`.
 */
final class ReconcileFixtures
{
    /**
     * @param  list<string>  $periods
     * @param  list<string>  $documentTypes
     */
    public static function run(
        Company $company,
        array $periods = ['2026-09'],
        array $documentTypes = ['01'],
        SriSyncRunStatus $status = SriSyncRunStatus::Invoking,
    ): SriSyncRun {
        $first = $periods[0] ?? '2026-09';
        $last = $periods === [] ? $first : $periods[count($periods) - 1];
        $from = CarbonImmutable::parse($first.'-01')->startOfMonth();
        $to = CarbonImmutable::parse($last.'-01')->endOfMonth();

        return SriSyncRun::create([
            'company_id' => $company->id,
            'status' => $status,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'periods' => $periods,
            'document_types' => $documentTypes,
            'idempotency_key' => 'reconcile-'.Str::uuid(),
            'finished_at' => $status->isFinal() ? now() : null,
            'totals' => [
                'found' => 0,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
            ],
        ]);
    }

    public static function path(Company $company, string $period): SriRobotObjectPath
    {
        return new SriRobotObjectPath('dev', $company->ruc, $period);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function manifest(
        Company $company,
        string $period,
        SriDocumentType $type,
        string $txt,
        array $keys,
    ): array {
        $path = self::path($company, $period);

        return [
            'ambiente' => 'dev',
            'ruc' => $company->ruc,
            'periodo' => $period,
            'generadoEn' => now()->toIso8601String(),
            'descargas' => [[
                'ruc' => $company->ruc,
                'periodo' => $period,
                'tipo' => $type->value,
                'tipoEtiqueta' => $type->label(),
                'claveTxt' => $path->textReport($type),
                'bytes' => strlen($txt),
                'totalClaves' => count($keys),
                'tareaCaptcha' => 'navegador',
            ]],
            'totalClaves' => count($keys),
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function keysPayload(
        Company $company,
        string $period,
        SriDocumentType $type,
        array $keys,
    ): array {
        $path = self::path($company, $period);

        return [
            'ambiente' => 'dev',
            'ruc' => $company->ruc,
            'periodo' => $period,
            'tipo' => $type->value,
            'tipoEtiqueta' => $type->label(),
            'archivo' => basename($path->textReport($type)),
            'total' => count($keys),
            'clavesAcceso' => $keys,
        ];
    }

    /**
     * @param  list<string>|null  $keys
     */
    public static function putPeriod(
        Company $company,
        string $period,
        SriDocumentType $type = SriDocumentType::Invoice,
        ?string $txt = null,
        ?array $keys = null,
        bool $withManifest = true,
        bool $withTxt = true,
        bool $withKeys = true,
    ): void {
        $txt ??= "contenido del TXT\n";
        $keys ??= [str_repeat('1', 49), str_repeat('2', 49)];
        $path = self::path($company, $period);

        if ($withManifest) {
            Storage::disk('sri')->put(
                $path->manifest(),
                json_encode(self::manifest($company, $period, $type, $txt, $keys), JSON_THROW_ON_ERROR),
            );
        }

        if ($withTxt) {
            Storage::disk('sri')->put($path->textReport($type), $txt);
        }

        if ($withKeys) {
            Storage::disk('sri')->put(
                $path->accessKeys($type),
                json_encode(self::keysPayload($company, $period, $type, $keys), JSON_THROW_ON_ERROR),
            );
        }
    }
}
