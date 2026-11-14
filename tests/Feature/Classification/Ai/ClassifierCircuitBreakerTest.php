<?php

declare(strict_types=1);

use App\Domain\Classification\Exceptions\ClassifierUnavailable;
use App\Domain\Classification\Services\ClassifierCircuitBreaker;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

it('abre el circuito al alcanzar el umbral de fallos', function (): void {
    $breaker = new ClassifierCircuitBreaker(failureThreshold: 2, cooldownSeconds: 60);

    $breaker->guard('1');
    $breaker->recordFailure('1');

    expect($breaker->isOpen('1'))->toBeFalse();

    $breaker->recordFailure('1');

    expect($breaker->isOpen('1'))->toBeTrue()
        ->and(fn () => $breaker->guard('1'))
        ->toThrow(ClassifierUnavailable::class);
});

it('cierra el circuito al agotar el enfriamiento', function (): void {
    $breaker = new ClassifierCircuitBreaker(failureThreshold: 1, cooldownSeconds: 30);

    $breaker->recordFailure('1');
    expect($breaker->isOpen('1'))->toBeTrue();

    $this->travel(29)->seconds();
    expect($breaker->isOpen('1'))->toBeTrue();

    $this->travel(2)->seconds();
    expect($breaker->isOpen('1'))->toBeFalse();
});

it('reinicia el circuito tras una llamada exitosa', function (): void {
    $breaker = new ClassifierCircuitBreaker(failureThreshold: 1, cooldownSeconds: 60);

    $breaker->recordFailure('1');
    $breaker->recordSuccess('1');

    expect($breaker->isOpen('1'))->toBeFalse();

    $breaker->guard('1');
});

it('mantiene circuitos separados por tenant', function (): void {
    $breaker = new ClassifierCircuitBreaker(failureThreshold: 1, cooldownSeconds: 60);

    $breaker->recordFailure('1');

    expect($breaker->isOpen('1'))->toBeTrue()
        ->and($breaker->isOpen('2'))->toBeFalse();
});
