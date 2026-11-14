<?php

declare(strict_types=1);

use App\Domain\Classification\Data\ClassificationCategory;
use App\Domain\Classification\Data\ClassificationRequest;
use App\Domain\Classification\Exceptions\InvalidClassifierOutput;
use App\Domain\Classification\Services\ClassifierOutputValidator;

function aiValidatorRequest(): ClassificationRequest
{
    return new ClassificationRequest(
        description: 'SERVICIOS DE CONSULTORIA CONTABLE',
        categories: [
            new ClassificationCategory(1, 'operativo', 'Gastos operativos'),
            new ClassificationCategory(2, 'alimentacion', 'Alimentación'),
        ],
    );
}

it('acepta una salida válida y normaliza el motivo', function (): void {
    $suggestion = (new ClassifierOutputValidator)->validate(
        '{"category":"operativo","confidence":0.92,"reason":"  Servicios profesionales  "}',
        aiValidatorRequest(),
    );

    expect($suggestion->categoryCode)->toBe('operativo')
        ->and($suggestion->confidence)->toBe(0.92)
        ->and($suggestion->reason)->toBe('Servicios profesionales');
});

it('rechaza claves adicionales o faltantes', function (): void {
    $validator = new ClassifierOutputValidator;
    $request = aiValidatorRequest();

    expect(fn () => $validator->validate(
        '{"category":"operativo","confidence":0.9,"reason":"ok","extra":true}',
        $request,
    ))->toThrow(InvalidClassifierOutput::class);

    expect(fn () => $validator->validate(
        '{"category":"operativo","confidence":0.9}',
        $request,
    ))->toThrow(InvalidClassifierOutput::class);
});

it('rechaza una categoría fuera del catálogo', function (): void {
    expect(fn () => (new ClassifierOutputValidator)->validate(
        '{"category":"inventada","confidence":0.9,"reason":"ok"}',
        aiValidatorRequest(),
    ))->toThrow(InvalidClassifierOutput::class);
});

it('rechaza confianzas que no son números o están fuera de rango', function (): void {
    $validator = new ClassifierOutputValidator;
    $request = aiValidatorRequest();

    foreach (['"0.9"', '1.5', '-0.1', 'true'] as $confidence) {
        expect(fn () => $validator->validate(
            '{"category":"operativo","confidence":'.$confidence.',"reason":"ok"}',
            $request,
        ))->toThrow(InvalidClassifierOutput::class);
    }
});

it('rechaza JSON truncado, markdown y listas', function (): void {
    $validator = new ClassifierOutputValidator;
    $request = aiValidatorRequest();

    foreach ([
        '{"category":"operativo","confidence":0.9',
        '```json\n{"category":"operativo","confidence":0.9,"reason":"ok"}\n```',
        '["operativo",0.9,"ok"]',
        '[]',
    ] as $raw) {
        expect(fn () => $validator->validate($raw, $request))
            ->toThrow(InvalidClassifierOutput::class);
    }
});

it('rechaza motivos vacíos o demasiado largos', function (): void {
    $validator = new ClassifierOutputValidator;
    $request = aiValidatorRequest();

    expect(fn () => $validator->validate(
        '{"category":"operativo","confidence":0.9,"reason":"   "}',
        $request,
    ))->toThrow(InvalidClassifierOutput::class);

    expect(fn () => $validator->validate(
        json_encode([
            'category' => 'operativo',
            'confidence' => 0.9,
            'reason' => str_repeat('a', 301),
        ], JSON_THROW_ON_ERROR),
        $request,
    ))->toThrow(InvalidClassifierOutput::class);
});

it('expone un JSON Schema estricto para el proveedor', function (): void {
    $schema = ClassifierOutputValidator::jsonSchema(['operativo', 'alimentacion']);

    expect($schema['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toBe(['category', 'confidence', 'reason'])
        ->and($schema['properties']['category']['enum'])->toBe(['operativo', 'alimentacion']);
});
