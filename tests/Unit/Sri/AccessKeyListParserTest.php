<?php

declare(strict_types=1);

use App\Domain\Sri\Data\SriAccessKey;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Parsers\AccessKeyListParser;
use App\Domain\Sri\Parsers\PeriodBatchBuilder;
use Tests\Support\Sri\AccessKeyFactory;

it('lee una clave por línea e ignora líneas vacías', function (): void {
    $first = AccessKeyFactory::make(sequential: '000000001');
    $second = AccessKeyFactory::make(sequential: '000000002');
    $result = (new AccessKeyListParser)->parse("{$first}\n\n{$second}\n", '2026-09');

    expect($result->values())->toBe([$first, $second])
        ->and($result->count())->toBe(2)
        ->and($result->emptyLines)->toBe(2)
        ->and($result->hasInvalidLines())->toBeFalse()
        ->and($result->accessKeys[0])->toBeInstanceOf(SriAccessKey::class);
});

it('tolera BOM, CRLF y espacios alrededor de la clave', function (): void {
    $key = AccessKeyFactory::make();
    $result = (new AccessKeyListParser)->parse("\xEF\xBB\xBF {$key} \r\n", '2026-09');

    expect($result->values())->toBe([$key]);
});

it('devuelve el motivo de cada línea inválida sin lanzar', function (): void {
    $valid = AccessKeyFactory::make();
    $result = (new AccessKeyListParser)->parse("{$valid}\nno-es-una-clave\n{$valid}0\n", '2026-09');

    expect($result->values())->toBe([$valid])
        ->and($result->invalidLines)->toHaveCount(2)
        ->and($result->invalidLines[0]['line'])->toBe(2)
        ->and($result->invalidLines[0]['value'])->toBe('no-es-una-clave')
        ->and($result->invalidLines[0]['reason'])->toContain('49 dígitos')
        ->and($result->invalidLines[1]['line'])->toBe(3);
});

it('descarta claves repetidas y las cuenta', function (): void {
    $key = AccessKeyFactory::make();
    $result = (new AccessKeyListParser)->parse("{$key}\n{$key}\n", '2026-09');

    expect($result->count())->toBe(1)
        ->and($result->duplicateLines)->toBe(1);
});

it('rechaza un período inválido para el TXT', function (): void {
    expect(fn () => (new AccessKeyListParser)->parse('', '2026-9'))
        ->toThrow(InvalidArgumentException::class, 'Período inválido');
});

it('cruza el TXT con el JSON de claves del robot', function (): void {
    $first = AccessKeyFactory::make(sequential: '000000001');
    $second = AccessKeyFactory::make(sequential: '000000002');

    $batch = (new PeriodBatchBuilder)->build(
        contents: "{$first}\n{$second}\n",
        period: '2026-09',
        documentType: SriDocumentType::Invoice,
        ruc: '1758871873001',
        listedKeys: [$first, $second],
    );

    expect($batch->values())->toBe([$first, $second])
        ->and($batch->isConsistent())->toBeTrue()
        ->and($batch->invalidAccessKeys)->toBe([]);
});

it('reporta claves del JSON que no llegaron en el TXT', function (): void {
    $first = AccessKeyFactory::make(sequential: '000000001');
    $missing = AccessKeyFactory::make(sequential: '000000002');

    $batch = (new PeriodBatchBuilder)->build(
        contents: "{$first}\n",
        period: '2026-09',
        documentType: SriDocumentType::Invoice,
        ruc: '1758871873001',
        listedKeys: [$first, $missing],
    );

    expect($batch->isConsistent())->toBeFalse()
        ->and($batch->missingAccessKeys)->toBe([$missing]);
});

it('reporta claves del TXT que el robot no declaró', function (): void {
    $declared = AccessKeyFactory::make(sequential: '000000001');
    $extra = AccessKeyFactory::make(sequential: '000000002');

    $batch = (new PeriodBatchBuilder)->build(
        contents: "{$declared}\n{$extra}\n",
        period: '2026-09',
        documentType: SriDocumentType::Invoice,
        ruc: '1758871873001',
        listedKeys: [$declared],
    );

    expect($batch->unlistedAccessKeys)->toBe([$extra])
        ->and($batch->accessKeys)->toHaveCount(2);
});

it('descarta claves de otro tipo, período o RUC', function (): void {
    $valid = AccessKeyFactory::make(sequential: '000000001');
    $creditNote = AccessKeyFactory::make(type: '04', sequential: '000000002');
    $otherPeriod = AccessKeyFactory::make(date: '10082026', sequential: '000000003');
    $otherRuc = AccessKeyFactory::make(ruc: '0999999999001', sequential: '000000004');

    $batch = (new PeriodBatchBuilder)->build(
        contents: "{$valid}\n{$creditNote}\n{$otherPeriod}\n{$otherRuc}\n",
        period: '2026-09',
        documentType: SriDocumentType::Invoice,
        ruc: '1758871873001',
        listedKeys: [$valid],
    );

    expect($batch->values())->toBe([$valid])
        ->and($batch->invalidAccessKeys)->toBe([$creditNote, $otherPeriod, $otherRuc])
        ->and($batch->isConsistent())->toBeTrue();
});

it('queda vacío cuando el TXT no tiene ninguna clave válida', function (): void {
    $batch = (new PeriodBatchBuilder)->build(
        contents: "basura\n",
        period: '2026-09',
        documentType: SriDocumentType::Invoice,
        ruc: '1758871873001',
        listedKeys: [],
    );

    expect($batch->isEmpty())->toBeTrue()
        ->and($batch->invalidAccessKeys)->toBe(['basura']);
});
