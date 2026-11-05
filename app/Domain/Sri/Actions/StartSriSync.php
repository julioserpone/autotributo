<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Contracts\SriRobotGateway;
use App\Domain\Sri\Data\SriRobotRequest;
use App\Domain\Sri\Data\SriSyncBatch;
use App\Domain\Sri\Data\SriSyncWindow;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Exceptions\SriGatewayException;
use App\Domain\Sri\Exceptions\SriRateLimitExceeded;
use App\Domain\Sri\Exceptions\SriSyncAlreadyRunning;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Crea y despacha los runs de una sincronización del robot.
 *
 * Si el rango supera `sri.sync.max_days_per_run` (93 por defecto) se generan
 * varios runs, uno por grupo de períodos. La clave de idempotencia evita
 * volver a invocar la Lambda cuando la misma solicitud se reintenta.
 *
 * Reglas de cuota:
 * - Solo puede haber un run activo por empresa. El bloqueo de caché por
 *   empresa serializa la comprobación para que dos solicitudes simultáneas no
 *   creen runs solapados.
 * - Las invocaciones se limitan por RUC con `sri.sync.max_calls_per_minute`
 *   antes de tocar la Lambda. Un reintento con la misma clave no consume cuota.
 */
final readonly class StartSriSync
{
    public function __construct(
        private SriRobotGateway $robot,
        private int $maxDaysPerRun = SriSyncWindow::MAX_DAYS,
        private int $maxCallsPerMinute = 6,
    ) {
        if ($maxDaysPerRun < 1) {
            throw new InvalidArgumentException('La ventana máxima por run debe ser mayor que cero.');
        }

        if ($maxCallsPerMinute < 1) {
            throw new InvalidArgumentException('El límite de llamadas por minuto debe ser mayor que cero.');
        }
    }

    public function handle(
        Company $company,
        SriRobotRequest $request,
        string $idempotencyKey,
        ?int $requestedBy = null,
    ): SriSyncBatch {
        $key = $this->normalizeKey($idempotencyKey);

        if ($company->ruc !== $request->ruc) {
            throw new InvalidArgumentException('El RUC de la empresa no coincide con la solicitud.');
        }

        $tenantId = app(TenantContext::class)->id();

        if ($company->tenant_id !== $tenantId) {
            throw new InvalidArgumentException('La empresa no pertenece al tenant activo.');
        }

        // Serializa la comprobación y creación de runs por empresa. Una misma
        // solicitud puede crear varios runs (rangos > 93 días), por eso el
        // bloqueo es de la solicitud completa y no de cada fila.
        try {
            /** @var SriSyncBatch $batch */
            $batch = Cache::lock('sri:start:'.$company->id, 30)->block(
                5,
                fn (): SriSyncBatch => $this->start($company, $request, $key, $requestedBy),
            );
        } catch (LockTimeoutException) {
            throw new SriSyncAlreadyRunning;
        }

        return $batch;
    }

    private function start(
        Company $company,
        SriRobotRequest $request,
        string $key,
        ?int $requestedBy,
    ): SriSyncBatch {
        $groups = SriSyncWindow::groupPeriods($request->periods, $this->maxDaysPerRun);
        $runKeys = $this->runKeys($key, count($groups));

        $this->assertNoConflictingRun($company, $runKeys);
        $this->reserveRateLimit($request->ruc, $this->pendingRunCount($company, $runKeys));

        $runs = [];

        foreach ($groups as $index => $periods) {
            $run = $this->createOrFindRun($company, $request, $periods, $runKeys[$index], $requestedBy);

            if (! $run->wasRecentlyCreated) {
                $runs[] = $run;

                continue;
            }

            $runs[] = $this->dispatch($run, $request, $periods);
        }

        return new SriSyncBatch($runs);
    }

    /**
     * Claves de idempotencia que produciría esta solicitud, en el mismo orden
     * que los grupos de períodos.
     *
     * @return list<string>
     */
    private function runKeys(string $base, int $groupCount): array
    {
        if ($groupCount === 1) {
            return [$base];
        }

        $keys = [];

        for ($index = 1; $index <= $groupCount; $index++) {
            $keys[] = $this->batchKey($base, $index);
        }

        return $keys;
    }

    /**
     * Un run activo de la empresa que no pertenezca a esta misma solicitud
     * bloquea la creación de otro. Las claves de esta solicitud se excluyen
     * para que un reintento siga siendo idempotente.
     *
     * @param  list<string>  $runKeys
     */
    private function assertNoConflictingRun(Company $company, array $runKeys): void
    {
        $conflict = SriSyncRun::query()
            ->where('company_id', $company->id)
            ->whereIn('status', SriSyncRunStatus::activeValues())
            ->whereNotIn('idempotency_key', $runKeys)
            ->orderBy('id')
            ->first();

        if ($conflict instanceof SriSyncRun) {
            throw SriSyncAlreadyRunning::forRun((string) $conflict->public_id);
        }
    }

    /**
     * Runs de esta solicitud que todavía no existen y, por tanto, consumirán
     * una invocación del robot.
     *
     * @param  list<string>  $runKeys
     */
    private function pendingRunCount(Company $company, array $runKeys): int
    {
        $existing = SriSyncRun::query()
            ->where('company_id', $company->id)
            ->whereIn('idempotency_key', $runKeys)
            ->pluck('idempotency_key')
            ->all();

        return max(0, count($runKeys) - count(array_unique($existing)));
    }

    /**
     * Reserva las invocaciones en la ventana de un minuto antes de crear o
     * despachar nada, para no dejar runs a medio crear si se supera la cuota.
     */
    private function reserveRateLimit(string $ruc, int $pendingRuns): void
    {
        if ($pendingRuns < 1) {
            return;
        }

        $rateKey = 'sri:sync:'.$ruc;
        $remaining = RateLimiter::remaining($rateKey, $this->maxCallsPerMinute);

        if ($remaining < $pendingRuns) {
            throw SriRateLimitExceeded::retryAfter((int) RateLimiter::availableIn($rateKey));
        }

        RateLimiter::increment($rateKey, 60, $pendingRuns);
    }

    /**
     * @param  list<string>  $periods
     */
    private function createOrFindRun(
        Company $company,
        SriRobotRequest $request,
        array $periods,
        string $runKey,
        ?int $requestedBy,
    ): SriSyncRun {
        $existing = SriSyncRun::query()
            ->where('company_id', $company->id)
            ->where('idempotency_key', $runKey)
            ->first();

        if ($existing instanceof SriSyncRun) {
            return $existing;
        }

        $window = SriSyncWindow::fromPeriods($periods);

        try {
            return DB::transaction(static fn (): SriSyncRun => SriSyncRun::create([
                'company_id' => $company->id,
                'requested_by' => $requestedBy,
                'status' => SriSyncRunStatus::Queued,
                'date_from' => $window->from->toDateString(),
                'date_to' => $window->to->toDateString(),
                'periods' => $periods,
                'document_types' => $request->documentTypes,
                'idempotency_key' => $runKey,
                'totals' => [
                    'found' => 0,
                    'imported' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                ],
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->resolveUniqueConflict($company, $runKey, $exception);
        }
    }

    /**
     * Resuelve una violación de unicidad sin ocultar el motivo:
     * - la misma clave ya existe para la empresa → reintento idempotente;
     * - la clave pertenece a otro tenant o empresa → error de entrada.
     */
    private function resolveUniqueConflict(
        Company $company,
        string $runKey,
        UniqueConstraintViolationException $exception,
    ): SriSyncRun {
        $existing = SriSyncRun::query()
            ->where('company_id', $company->id)
            ->where('idempotency_key', $runKey)
            ->first();

        if ($existing instanceof SriSyncRun) {
            return $existing;
        }

        $usedElsewhere = SriSyncRun::query()
            ->withoutGlobalScopes()
            ->where('idempotency_key', $runKey)
            ->exists();

        if ($usedElsewhere) {
            throw new InvalidArgumentException('La clave de idempotencia ya está en uso.');
        }

        throw $exception;
    }

    /**
     * @param  list<string>  $periods
     */
    private function dispatch(SriSyncRun $run, SriRobotRequest $request, array $periods): SriSyncRun
    {
        $groupRequest = new SriRobotRequest(
            ruc: $request->ruc,
            periods: $periods,
            documentTypes: $request->documentTypes,
            force: $request->force,
            credentials: $request->credentials,
        );

        try {
            $dispatch = $this->robot->dispatch($groupRequest);
        } catch (Throwable $exception) {
            $run->markFailed(
                'robot_invocation_failed',
                'No fue posible invocar el robot SRI.',
            );

            throw $exception;
        }

        if (! $dispatch->isAccepted()) {
            $run->markFailed(
                'robot_invocation_rejected',
                'El robot rechazó la invocación.',
            );

            throw SriGatewayException::robotInvocationFailed(
                $dispatch->functionName,
                new RuntimeException('Invocación rechazada.'),
            );
        }

        $run->markInvoking($dispatch->requestId, $dispatch->eventId);

        return $run->refresh();
    }

    private function normalizeKey(string $idempotencyKey): string
    {
        $key = trim($idempotencyKey);

        if ($key === '' || strlen($key) > 64) {
            throw new InvalidArgumentException('La clave de idempotencia debe tener entre 1 y 64 caracteres.');
        }

        return $key;
    }

    private function batchKey(string $base, int $index): string
    {
        $suffix = '-'.$index;

        return substr($base, 0, 64 - strlen($suffix)).$suffix;
    }
}
