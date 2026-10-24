<?php

declare(strict_types=1);

use App\Domain\Sri\Enums\SriSyncRunStatus;

it('permite el camino feliz de una sincronización', function (): void {
    expect(SriSyncRunStatus::Queued->canTransitionTo(SriSyncRunStatus::Invoking))->toBeTrue()
        ->and(SriSyncRunStatus::Invoking->canTransitionTo(SriSyncRunStatus::Downloading))->toBeTrue()
        ->and(SriSyncRunStatus::Downloading->canTransitionTo(SriSyncRunStatus::Parsing))->toBeTrue()
        ->and(SriSyncRunStatus::Parsing->canTransitionTo(SriSyncRunStatus::Completed))->toBeTrue();
});

it('permite fallar o cancelar desde estados activos', function (SriSyncRunStatus $from): void {
    expect($from->canTransitionTo(SriSyncRunStatus::Failed))->toBeTrue()
        ->and($from->canTransitionTo(SriSyncRunStatus::Cancelled))->toBeTrue();
})->with([
    SriSyncRunStatus::Queued,
    SriSyncRunStatus::Invoking,
    SriSyncRunStatus::Downloading,
    SriSyncRunStatus::Parsing,
]);

it('marca los estados terminales y no los reabre', function (SriSyncRunStatus $status): void {
    expect($status->isFinal())->toBeTrue()
        ->and($status->isActive())->toBeFalse()
        ->and($status->canTransitionTo(SriSyncRunStatus::Queued))->toBeFalse();
})->with([
    SriSyncRunStatus::Completed,
    SriSyncRunStatus::Partial,
    SriSyncRunStatus::Failed,
    SriSyncRunStatus::Cancelled,
]);

it('rechaza saltos de estado no permitidos', function (): void {
    expect(SriSyncRunStatus::Queued->canTransitionTo(SriSyncRunStatus::Completed))->toBeFalse()
        ->and(SriSyncRunStatus::Invoking->canTransitionTo(SriSyncRunStatus::Parsing))->toBeFalse()
        ->and(SriSyncRunStatus::Downloading->canTransitionTo(SriSyncRunStatus::Completed))->toBeFalse();
});
