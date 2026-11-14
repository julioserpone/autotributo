<?php

declare(strict_types=1);

use App\Domain\Classification\Data\ClassificationCategory;
use App\Domain\Classification\Data\ClassificationRequest;
use App\Domain\Classification\Services\ClassifierPrompt;

function aiPromptRequest(): ClassificationRequest
{
    return new ClassificationRequest(
        description: 'PAPELERIA Y SUMINISTROS',
        issuerActivity: 'COMERCIO AL POR MAYOR',
        categories: [
            new ClassificationCategory(1, 'operativo', 'Gastos operativos'),
            new ClassificationCategory(2, 'alimentacion', 'Alimentación'),
        ],
    );
}

it('interpola las categorías en el prompt versionado', function (): void {
    $prompt = new ClassifierPrompt;
    $system = $prompt->system(aiPromptRequest());

    expect(ClassifierPrompt::VERSION)->toBe('v1')
        ->and($system)->toContain('- operativo: Gastos operativos')
        ->and($system)->toContain('- alimentacion: Alimentación')
        ->and($system)->not->toContain('{{categories}}')
        ->and($system)->toContain('EXCLUSIVAMENTE un objeto JSON');
});

it('envía la descripción como datos no confiables y sin identificadores', function (): void {
    $payload = json_decode(
        (new ClassifierPrompt)->user(aiPromptRequest()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($payload)->toBe([
        'descripcion' => 'PAPELERIA Y SUMINISTROS',
        'actividad_emisor' => 'COMERCIO AL POR MAYOR',
    ])->not->toHaveKey('ruc');
});

it('omite la actividad del emisor cuando no está disponible', function (): void {
    $payload = json_decode(
        (new ClassifierPrompt)->user(new ClassificationRequest(
            description: 'SERVICIO',
            categories: [new ClassificationCategory(1, 'operativo', 'Gastos operativos')],
        )),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($payload)->toBe(['descripcion' => 'SERVICIO']);
});
