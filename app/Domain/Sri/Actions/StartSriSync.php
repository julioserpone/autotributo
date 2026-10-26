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
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Crea y despacha los runs de una sincronización del robot.
 *
 * Si el rango supera 93 días se generan varios runs, uno por grupo de
 * períodos. La clave de idempotencia evita volver a invocar la Lambda cuando
 * la misma solicitud se reintenta.
 */
final readonly class StartSriSync
{
    public function __construct(private SriRobotGateway $robot) {}

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

        $groups = SriSyncWindow::groupPeriods($request->periods);
        $runs = [];

        foreach ($groups as $index => $periods) {
            $runKey = count($groups) === 1
                ? $key
                : $this->batchKey($key, $index + 1);

            $run = $this->createOrFindRun($company, $request, $periods, $runKey, $requestedBy);

            if (! $run->wasRecentlyCreated) {
                $runs[] = $run;

                continue;
            }

            $runs[] = $this->dispatch($run, $request, $periods);
        }

        return new SriSyncBatch($runs);
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
        } catch (UniqueConstraintViolationException) {
            return SriSyncRun::query()
                ->where('idempotency_key', $runKey)
                ->firstOrFail();
        }
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
