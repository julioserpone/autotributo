<?php

declare(strict_types=1);

use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Services\ClassificationRuleMatcher;
use App\Domain\Invoicing\Models\InvoiceItem;

/**
 * Construye una regla sin persistirla: el matcher solo lee atributos.
 *
 * @param  array<string, mixed>  $overrides
 */
function matcherRule(MatchType $type, string $pattern, array $overrides = []): ClassificationRule
{
    $rule = new ClassificationRule;
    $rule->forceFill([
        'name' => 'Regla de prueba',
        'priority' => 100,
        'match_type' => $type,
        'pattern' => $pattern,
        'expense_category_id' => 1,
        'is_active' => true,
        ...$overrides,
    ]);

    return $rule;
}

/**
 * Construye un ítem sin persistirlo.
 */
function matcherItem(string $description, ?string $mainCode = null, ?string $auxiliaryCode = null): InvoiceItem
{
    $item = new InvoiceItem;
    $item->forceFill([
        'description' => $description,
        'main_code' => $mainCode,
        'auxiliary_code' => $auxiliaryCode,
    ]);

    return $item;
}

beforeEach(function (): void {
    $this->matcher = new ClassificationRuleMatcher;
});

it('encuentra la descripción con contains ignorando mayúsculas y acentos', function (): void {
    $rule = matcherRule(MatchType::Contains, 'pan integral');

    expect($this->matcher->matches($rule, matcherItem('PAN   INTEGRAL 100%'), null))->toBeTrue()
        ->and($this->matcher->matches($rule, matcherItem('Panificación artesanal'), null))->toBeFalse();
});

it('acierta con starts_with solo al inicio de la descripción', function (): void {
    $rule = matcherRule(MatchType::StartsWith, 'Servicios');

    expect($this->matcher->matches($rule, matcherItem('Servicios de consultoría'), null))->toBeTrue()
        ->and($this->matcher->matches($rule, matcherItem('Otros servicios varios'), null))->toBeFalse();
});

it('acierta con exact comparando la descripción normalizada', function (): void {
    $rule = matcherRule(MatchType::Exact, 'SUMINISTROS BÁSICOS');

    expect($this->matcher->matches($rule, matcherItem('Suministros basicos'), null))->toBeTrue()
        ->and($this->matcher->matches($rule, matcherItem('Suministros basicos del mes'), null))->toBeFalse();
});

it('evalúa expresiones regulares y descarta las inválidas', function (): void {
    $rule = matcherRule(MatchType::Regex, '/^FACTURA\s+N[ºo]?\s*\d+/iu');

    expect($this->matcher->matches($rule, matcherItem('Factura Nº 001-001'), null))->toBeTrue()
        ->and($this->matcher->matches($rule, matcherItem('Nota de venta 001'), null))->toBeFalse();

    $invalid = matcherRule(MatchType::Regex, '/[a-/');

    expect(ClassificationRuleMatcher::isValidRegex('/[a-/'))->toBeFalse()
        ->and($this->matcher->matches($invalid, matcherItem('Cualquier texto'), null))->toBeFalse();
});

it('compara el RUC del proveedor cuando el emisor existe', function (): void {
    $rule = matcherRule(MatchType::ProviderRuc, '1758871873001');

    expect($this->matcher->matches($rule, matcherItem('Servicio'), '1758871873001'))->toBeTrue()
        ->and($this->matcher->matches($rule, matcherItem('Servicio'), '1790012345001'))->toBeFalse()
        ->and($this->matcher->matches($rule, matcherItem('Servicio'), null))->toBeFalse();
});

it('compara el código principal o auxiliar del ítem', function (): void {
    $rule = matcherRule(MatchType::ItemCode, 'SRV-001');

    expect($this->matcher->matches($rule, matcherItem('Servicio', 'srv-001'), null))->toBeTrue()
        ->and($this->matcher->matches($rule, matcherItem('Servicio', null, 'SRV-001'), null))->toBeTrue()
        ->and($this->matcher->matches($rule, matcherItem('Servicio', 'SRV-002', null), null))->toBeFalse()
        ->and($this->matcher->matches($rule, matcherItem('Servicio', null, null), null))->toBeFalse();
});

it('devuelve la primera regla que coincide y null si ninguna lo hace', function (): void {
    $rules = [
        matcherRule(MatchType::Contains, 'NO EXISTE'),
        matcherRule(MatchType::Contains, 'CONSULTORIA'),
        matcherRule(MatchType::Contains, 'SERVICIOS'),
    ];

    $match = $this->matcher->firstMatch($rules, matcherItem('Servicios de consultoría contable'), null);

    expect($match)->not->toBeNull()
        ->and($match?->pattern)->toBe('CONSULTORIA')
        ->and($this->matcher->firstMatch($rules, matcherItem('Suministros'), null))->toBeNull();
});

it('no coincide cuando el patrón está vacío', function (): void {
    $rule = matcherRule(MatchType::Contains, '   ');

    expect($this->matcher->matches($rule, matcherItem('Cualquier descripción'), null))->toBeFalse();
});

it('normaliza espacios repetidos y tildes', function (): void {
    expect($this->matcher->normalize('  Pan   de   molde  '))->toBe('PAN DE MOLDE')
        ->and($this->matcher->normalize('Educación'))->toBe('EDUCACION');
});
