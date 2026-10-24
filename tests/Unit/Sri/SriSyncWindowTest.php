<?php

declare(strict_types=1);

use App\Domain\Sri\Data\SriSyncWindow;
use Carbon\CarbonImmutable;

it('calcula el rango que cubren varios períodos', function (): void {
    $window = SriSyncWindow::fromPeriods(['2026-09', '2026-08-15']);

    expect($window->from->toDateString())->toBe('2026-08-15')
        ->and($window->to->toDateString())->toBe('2026-09-30')
        ->and($window->days())->toBe(47);
});

it('agrupa meses completos sin superar 93 días', function (): void {
    $groups = SriSyncWindow::groupPeriods([
        '2026-01',
        '2026-02',
        '2026-03',
        '2026-04',
        '2026-05',
    ]);

    expect($groups)->toBe([
        ['2026-01', '2026-02', '2026-03'],
        ['2026-04', '2026-05'],
    ]);
});

it('agrupa períodos diarios en bloques de 93 días', function (): void {
    $start = CarbonImmutable::parse('2026-01-01');
    $periods = array_map(
        static fn (int $offset): string => $start->addDays($offset)->toDateString(),
        range(0, 99),
    );

    $groups = SriSyncWindow::groupPeriods($periods);

    expect($groups)->toHaveCount(2)
        ->and($groups[0])->toHaveCount(93)
        ->and($groups[1])->toHaveCount(7)
        ->and($groups[0][0])->toBe('2026-01-01')
        ->and($groups[0][92])->toBe('2026-04-03')
        ->and($groups[1][0])->toBe('2026-04-04');
});

it('divide un rango exacto de 93 días en una sola ventana', function (): void {
    $windows = SriSyncWindow::split(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-04-03'),
    );

    expect($windows)->toHaveCount(1)
        ->and($windows[0]->days())->toBe(93);
});

it('divide rangos mayores sin dejar huecos ni solapamientos', function (): void {
    $windows = SriSyncWindow::split(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
    );

    expect($windows)->toHaveCount(4)
        ->and($windows[0]->days())->toBe(93)
        ->and($windows[1]->days())->toBe(93)
        ->and($windows[2]->days())->toBe(93)
        ->and($windows[3]->days())->toBe(86);

    foreach ($windows as $index => $window) {
        if ($index === 0) {
            continue;
        }

        expect($windows[$index - 1]->to->addDay()->toDateString())
            ->toBe($window->from->toDateString());
    }
});

it('rechaza períodos o rangos inválidos', function (): void {
    expect(fn () => SriSyncWindow::fromPeriods([]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => SriSyncWindow::fromPeriods(['2026-13']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => SriSyncWindow::split(
        CarbonImmutable::parse('2026-02-01'),
        CarbonImmutable::parse('2026-01-01'),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => SriSyncWindow::groupPeriods(['2026-01'], 0))
        ->toThrow(InvalidArgumentException::class);
});
