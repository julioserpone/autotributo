<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sri\Models\SriSyncRun;
use Illuminate\Console\Command;
use Throwable;

/**
 * Resumen operativo de las sincronizaciones SRI de los últimos días.
 *
 * Lee `sri_sync_runs` directamente (sin el scope por tenant) para que un
 * operador pueda ver la salud global del pipeline. La salida en JSON permite
 * alimentar un dashboard o un cron de alerta.
 */
final class SriMetricsCommand extends Command
{
    protected $signature = 'sri:metrics
                            {--days=7 : Ventana en días hacia atrás}
                            {--json : Emitir el resultado como JSON}';

    protected $description = 'Muestra métricas de sincronización SRI (duración, volumen y tasa de fallo)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        try {
            $runs = SriSyncRun::query()
                ->withoutGlobalScopes()
                ->where('created_at', '>=', now()->subDays($days))
                ->get();
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No fue posible obtener las métricas del pipeline SRI.');

            return self::FAILURE;
        }

        $statuses = [
            'completed' => 0,
            'partial' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'active' => 0,
        ];

        $totals = [
            'found' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'from_s3' => 0,
            'from_sri' => 0,
        ];

        $durations = [];

        foreach ($runs as $run) {
            $status = $run->status->value;

            if (array_key_exists($status, $statuses)) {
                $statuses[$status]++;
            } else {
                $statuses['active']++;
            }

            foreach ($totals as $key => $value) {
                $totals[$key] += (int) ($run->totals[$key] ?? 0);
            }

            $start = $run->started_at ?? $run->created_at;
            $end = $run->finished_at;

            if ($start !== null && $end !== null) {
                $durations[] = max(0.0, (float) $start->diffInSeconds($end));
            }
        }

        $total = $runs->count();
        $failed = $statuses['failed'] + $statuses['partial'];
        $failureRate = $total > 0 ? round($failed / $total, 4) : 0.0;

        $metrics = [
            'window_days' => $days,
            'runs' => [
                'total' => $total,
                ...$statuses,
            ],
            'duration' => [
                'avg_seconds' => $this->average($durations),
                'p95_seconds' => $this->percentile($durations, 95),
                'max_seconds' => empty($durations) ? 0.0 : (float) max($durations),
                'samples' => count($durations),
            ],
            'volume' => $totals,
            'failure_rate' => $failureRate,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Ventana (días)', $days],
                ['Runs totales', $total],
                ['Completados', $statuses['completed']],
                ['Parciales', $statuses['partial']],
                ['Fallidos', $statuses['failed']],
                ['Cancelados', $statuses['cancelled']],
                ['Activos', $statuses['active']],
                ['Duración media (s)', $this->average($durations)],
                ['Duración p95 (s)', $this->percentile($durations, 95)],
                ['Comprobantes encontrados', $totals['found']],
                ['Importados', $totals['imported']],
                ['Actualizados', $totals['updated']],
                ['Saltados', $totals['skipped']],
                ['Con fallo', $totals['failed']],
                ['Tasa de fallo', $failureRate],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @param  list<float>  $values
     */
    private function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * @param  list<float>  $values
     */
    private function percentile(array $values, int $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);

        $index = (int) ceil(($percentile / 100) * count($values)) - 1;
        $index = max(0, min(count($values) - 1, $index));

        return round($values[$index], 2);
    }
}
