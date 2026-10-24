<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Rango de fechas que puede cubrir una sola invocación del robot.
 *
 * Lambda tiene un límite de 15 minutos; el plan fija 93 días por ejecución.
 * Un rango mayor se divide en ventanas contiguas y sin solapamientos.
 */
final readonly class SriSyncWindow
{
    public const MAX_DAYS = 93;

    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(CarbonImmutable $from, CarbonImmutable $to)
    {
        $this->from = $from->startOfDay();
        $this->to = $to->startOfDay();

        if ($this->from->greaterThan($this->to)) {
            throw new InvalidArgumentException('La fecha inicial no puede ser posterior a la final.');
        }
    }

    /**
     * Calcula el rango que cubre uno o varios períodos del contrato del robot.
     *
     * @param  list<string>  $periods  Formatos YYYY-MM o YYYY-MM-DD.
     */
    public static function fromPeriods(array $periods): self
    {
        if ($periods === []) {
            throw new InvalidArgumentException('Se necesita al menos un período para construir la ventana.');
        }

        $starts = [];
        $ends = [];

        foreach ($periods as $period) {
            [$start, $end] = self::parsePeriod($period);
            $starts[] = $start;
            $ends[] = $end;
        }

        return new self(
            self::earliest($starts),
            self::latest($ends),
        );
    }

    /**
     * Agrupa períodos contiguos sin superar el máximo de días por invocación.
     *
     * El robot recibe períodos (meses o días), no un rango arbitrario. Por eso
     * se agrupan períodos completos en lugar de cortar un mes por la mitad.
     *
     * @param  list<string>  $periods  Formatos YYYY-MM o YYYY-MM-DD.
     * @return list<list<string>>
     */
    public static function groupPeriods(array $periods, int $maxDays = self::MAX_DAYS): array
    {
        if ($periods === []) {
            throw new InvalidArgumentException('Se necesita al menos un período para agrupar.');
        }

        if ($maxDays < 1) {
            throw new InvalidArgumentException('La ventana debe tener al menos un día.');
        }

        $items = [];

        foreach ($periods as $period) {
            [$start, $end] = self::parsePeriod($period);
            $items[] = ['period' => $period, 'start' => $start, 'end' => $end];
        }

        usort(
            $items,
            static fn (array $a, array $b): int => $a['start']->getTimestamp() <=> $b['start']->getTimestamp(),
        );

        $groups = [];
        $current = [];
        $groupStart = null;
        $groupEnd = null;

        foreach ($items as $item) {
            if ($current === []) {
                $current = [$item['period']];
                $groupStart = $item['start'];
                $groupEnd = $item['end'];

                continue;
            }

            $candidateEnd = $item['end']->greaterThan($groupEnd) ? $item['end'] : $groupEnd;
            $candidateDays = (int) $groupStart->diffInDays($candidateEnd) + 1;

            if ($candidateDays <= $maxDays) {
                $current[] = $item['period'];
                $groupEnd = $candidateEnd;

                continue;
            }

            $groups[] = $current;
            $current = [$item['period']];
            $groupStart = $item['start'];
            $groupEnd = $item['end'];
        }

        $groups[] = $current;

        return $groups;
    }

    /**
     * Divide un rango inclusivo en ventanas de como máximo `$maxDays` días.
     *
     * @return list<self>
     */
    public static function split(
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $maxDays = self::MAX_DAYS,
    ): array {
        if ($maxDays < 1) {
            throw new InvalidArgumentException('La ventana debe tener al menos un día.');
        }

        $range = new self($from, $to);
        $windows = [];
        $cursor = $range->from;

        while ($cursor->lessThanOrEqualTo($range->to)) {
            $end = $cursor->addDays($maxDays - 1);

            if ($end->greaterThan($range->to)) {
                $end = $range->to;
            }

            $windows[] = new self($cursor, $end);
            $cursor = $end->addDay();
        }

        return $windows;
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function parsePeriod(string $period): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])(-(0[1-9]|[12]\d|3[01]))?$/', $period) !== 1) {
            throw new InvalidArgumentException("Período inválido: {$period}.");
        }

        $start = strlen($period) === 7
            ? CarbonImmutable::createFromFormat('!Y-m', $period)
            : CarbonImmutable::createFromFormat('!Y-m-d', $period);

        if ($start === null) {
            throw new InvalidArgumentException("Período inválido: {$period}.");
        }

        return [$start, strlen($period) === 7 ? $start->endOfMonth() : $start];
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     */
    private static function earliest(array $dates): CarbonImmutable
    {
        usort($dates, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $dates[0];
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     */
    private static function latest(array $dates): CarbonImmutable
    {
        usort($dates, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $b->getTimestamp() <=> $a->getTimestamp());

        return $dates[0];
    }
}
