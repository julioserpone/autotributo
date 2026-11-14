<?php

declare(strict_types=1);

namespace App\Domain\Classification\Actions;

use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Models\Classification;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Services\ClassificationRuleMatcher;
use App\Domain\Classification\Services\ClassificationRuleRepository;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;

/**
 * Clasifica los ítems de un comprobante con el motor de reglas.
 *
 * US-16: una regla que coincide clasifica con `method=rule` y confianza 1.0,
 * sin consumir presupuesto de IA. Si no hay reglas o ninguna coincide, el ítem
 * queda `pending` para que F3-03/F3-05 lo resuelvan.
 *
 * Los contadores de aciertos se actualizan con consultas directas para no
 * disparar `saved` ni invalidar la caché del catálogo en cada ítem.
 */
final readonly class ClassifyInvoiceItems
{
    public function __construct(
        private ClassificationRuleMatcher $matcher,
        private ClassificationRuleRepository $rules,
    ) {}

    public function handle(Invoice $invoice): int
    {
        $rules = $this->rules->forTenant((int) $invoice->tenant_id);

        if ($rules === []) {
            return 0;
        }

        $classified = 0;
        /** @var array<int, int> $hits */
        $hits = [];

        foreach ($invoice->items()->get() as $item) {
            if (! $item instanceof InvoiceItem) {
                continue;
            }

            $issuerRuc = $invoice->issuer_ruc;

            $rule = $this->matcher->firstMatch(
                $rules,
                $item,
                $issuerRuc === null ? null : (string) $issuerRuc,
            );

            if (! $rule instanceof ClassificationRule) {
                continue;
            }

            $item->forceFill([
                'expense_category_id' => $rule->expense_category_id,
                'classification_status' => InvoiceItem::STATUS_AUTO,
            ])->save();

            Classification::query()->updateOrCreate(
                ['invoice_item_id' => $item->id],
                [
                    'tenant_id' => $invoice->tenant_id,
                    'expense_category_id' => $rule->expense_category_id,
                    'method' => ClassificationMethod::Rule,
                    'confidence' => 1.0,
                    'status' => ClassificationStatus::Suggested,
                    'rule_id' => $rule->id,
                ],
            );

            $hits[$rule->id] = ($hits[$rule->id] ?? 0) + 1;
            $classified++;
        }

        foreach ($hits as $ruleId => $count) {
            ClassificationRule::withoutGlobalScopes()
                ->whereKey($ruleId)
                ->increment('hits_count', $count, ['last_matched_at' => now()]);
        }

        return $classified;
    }
}
