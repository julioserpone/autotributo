<?php

declare(strict_types=1);

namespace App\Domain\Classification\Actions;

use App\Domain\Classification\Data\ClassificationRulePreview;
use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Services\ClassificationRuleConflictDetector;
use App\Domain\Classification\Services\ClassificationRuleMatcher;
use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Dry-run de una regla (US-19).
 *
 * Evalúa la regla candidata contra los ítems ya importados y devuelve cuántos
 * afectaría, una muestra y los conflictos con otras reglas. No escribe nada.
 */
final readonly class PreviewClassificationRule
{
    public function __construct(
        private ClassificationRuleConflictDetector $detector,
        private ClassificationRuleMatcher $matcher,
    ) {}

    /**
     * @param  array<string, mixed>  $criteria
     */
    public function handle(array $criteria): ClassificationRulePreview
    {
        $rule = $this->candidate($criteria);
        $limit = max(1, min((int) ($criteria['limit'] ?? 10), 50));

        if ($rule->match_type->isRegex() && ! ClassificationRuleMatcher::isValidRegex((string) $rule->pattern)) {
            throw new InvalidArgumentException('La expresión regular de la regla no es válida.');
        }

        $conflicts = $this->detector->detect(
            $rule->match_type,
            (string) $rule->pattern,
            (int) $rule->priority,
            $rule->exists ? $rule->getKey() : null,
        );

        $query = InvoiceItem::query()->with('invoice');

        $companyPublicId = trim((string) ($criteria['company_id'] ?? ''));

        if ($companyPublicId !== '') {
            $companyId = Company::query()->where('public_id', $companyPublicId)->value('id');

            if ($companyId === null) {
                return new ClassificationRulePreview(0, 0, [], $conflicts);
            }

            $query->whereHas(
                'invoice',
                fn (Builder $builder): Builder => $builder->where('company_id', $companyId),
            );
        }

        $matches = 0;
        $scanned = 0;
        $sample = [];

        $query->orderBy('id')->chunkById(200, function ($items) use ($rule, $limit, &$matches, &$scanned, &$sample): void {
            foreach ($items as $item) {
                if (! $item instanceof InvoiceItem) {
                    continue;
                }

                $scanned++;
                $invoice = $item->invoice;
                $issuerRuc = $invoice?->issuer_ruc;

                if (! $this->matcher->matches($rule, $item, $issuerRuc === null ? null : (string) $issuerRuc)) {
                    continue;
                }

                $matches++;

                if (count($sample) < $limit) {
                    $sample[] = [
                        'invoice_item_id' => $item->id,
                        'description' => $item->description,
                        'issuer_ruc' => $issuerRuc,
                        'issuer_name' => $invoice?->issuer_name,
                        'issue_date' => $invoice?->issue_date?->toDateString(),
                        'invoice_id' => $invoice?->public_id,
                    ];
                }
            }
        });

        return new ClassificationRulePreview($matches, $scanned, $sample, $conflicts);
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function candidate(array $criteria): ClassificationRule
    {
        if (isset($criteria['rule_id'])) {
            return ClassificationRule::query()->findOrFail((int) $criteria['rule_id']);
        }

        $rule = new ClassificationRule;
        $rule->forceFill([
            'name' => (string) ($criteria['name'] ?? 'Vista previa'),
            'match_type' => MatchType::from((string) $criteria['match_type']),
            'pattern' => trim((string) $criteria['pattern']),
            'priority' => (int) ($criteria['priority'] ?? 100),
            'is_active' => true,
        ]);

        return $rule;
    }
}
