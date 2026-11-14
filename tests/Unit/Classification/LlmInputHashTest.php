<?php

declare(strict_types=1);

use App\Domain\Classification\Data\ClassificationCategory;
use App\Domain\Classification\Data\ClassificationRequest;
use App\Domain\Classification\Services\ClassificationRuleMatcher;
use App\Domain\Classification\Services\LlmInputHash;

function inputHashService(): LlmInputHash
{
    return new LlmInputHash(new ClassificationRuleMatcher);
}

/**
 * @param  list<string>  $codes
 */
function hashRequest(string $description, array $codes = ['operativo', 'alimentacion'], ?string $activity = null): ClassificationRequest
{
    $categories = [];

    foreach ($codes as $index => $code) {
        $categories[] = new ClassificationCategory($index + 1, $code, ucfirst($code));
    }

    return new ClassificationRequest($description, $activity, $categories);
}

it('genera la misma huella para la descripción normalizada', function (): void {
    $service = inputHashService();

    $first = $service->for(hashRequest('SERVICIOS DE CONSULTORIA'), 'gpt-4.1-mini', 'v1');
    $second = $service->for(hashRequest('  servicios   de consultoría '), 'gpt-4.1-mini', 'v1');

    expect($first)->toHaveLength(64)
        ->and($second)->toBe($first);
});

it('ignora el orden del catálogo pero no su contenido', function (): void {
    $service = inputHashService();

    $ordered = $service->for(hashRequest('SERVICIO', ['operativo', 'alimentacion']), 'gpt-4.1-mini', 'v1');
    $reordered = $service->for(hashRequest('SERVICIO', ['alimentacion', 'operativo']), 'gpt-4.1-mini', 'v1');
    $other = $service->for(hashRequest('SERVICIO', ['operativo', 'transporte']), 'gpt-4.1-mini', 'v1');

    expect($reordered)->toBe($ordered)
        ->and($other)->not->toBe($ordered);
});

it('cambia la huella con el modelo, el prompt o el rubro del emisor', function (): void {
    $service = inputHashService();
    $request = hashRequest('SERVICIO', activity: 'Consultoría');

    $base = $service->for($request, 'gpt-4.1-mini', 'v1');

    expect($service->for($request, 'gpt-4.1', 'v1'))->not->toBe($base)
        ->and($service->for($request, 'gpt-4.1-mini', 'v2'))->not->toBe($base)
        ->and($service->for(hashRequest('SERVICIO', activity: 'Comercio'), 'gpt-4.1-mini', 'v1'))
        ->not->toBe($base);
});
