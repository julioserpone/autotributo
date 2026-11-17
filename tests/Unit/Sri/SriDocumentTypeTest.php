<?php

declare(strict_types=1);

use App\Domain\Sri\Enums\SriDocumentType;

it('incluye la guía de remisión entre los tipos que el parser entiende', function (): void {
    expect(SriDocumentType::codes())->toBe(['01', '03', '04', '05', '06', '07']);
});

it('excluye la guía de remisión de los tipos que el robot descarga del portal', function (): void {
    expect(SriDocumentType::syncableCodes())->toBe(['01', '03', '04', '05', '07']);
});
