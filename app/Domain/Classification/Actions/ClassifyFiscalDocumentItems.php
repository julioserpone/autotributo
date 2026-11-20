<?php

declare(strict_types=1);

namespace App\Domain\Classification\Actions;

use App\Domain\Classification\Contracts\Classifier;
use App\Domain\Classification\Data\ClassificationCategory;
use App\Domain\Classification\Data\ClassificationRequest;
use App\Domain\Classification\Data\ClassificationSuggestion;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Exceptions\ClassifierUnavailable;
use App\Domain\Classification\Exceptions\InvalidClassifierOutput;
use App\Domain\Classification\Models\Classification;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Classification\Services\ClassificationRuleMatcher;
use App\Domain\Classification\Services\ClassificationRuleRepository;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\FiscalDocuments\Models\FiscalDocumentItem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clasifica los ítems de un comprobante: primero reglas, luego IA.
 *
 * US-16: una regla que coincide clasifica con `method=rule` y confianza 1.0,
 * sin consumir presupuesto de IA. US-17: si ninguna regla coincide, el
 * clasificador propone una categoría; con confianza mayor o igual al umbral el
 * ítem queda `auto`, con menor confianza queda en revisión.
 *
 * La IA es un respaldo best-effort: timeout, 429, salida inválida o circuito
 * abierto dejan el ítem en `review` sin bloquear la importación. Un clasificador
 * sin sugerencia (el fake por defecto) mantiene el ítem `pending`.
 */
final readonly class ClassifyFiscalDocumentItems
{
    public function __construct(
        private ClassificationRuleMatcher $matcher,
        private ClassificationRuleRepository $rules,
        private Classifier $classifier,
        private float $confidenceThreshold,
    ) {}

    public function handle(FiscalDocument $fiscal_document): int
    {
        $rules = $this->rules->forTenant((int) $fiscal_document->tenant_id);

        $classified = 0;
        /** @var array<int, int> $hits */
        $hits = [];
        /** @var list<ClassificationCategory>|null $categories */
        $categories = null;

        foreach ($fiscal_document->items()->get() as $item) {
            if (! $item instanceof FiscalDocumentItem) {
                continue;
            }

            $issuerRuc = $fiscal_document->issuer_ruc;

            $rule = $this->matcher->firstMatch(
                $rules,
                $item,
                $issuerRuc === null ? null : (string) $issuerRuc,
            );

            if ($rule instanceof ClassificationRule) {
                $item->forceFill([
                    'expense_category_id' => $rule->expense_category_id,
                    'classification_status' => FiscalDocumentItem::STATUS_AUTO,
                ])->save();

                Classification::query()->updateOrCreate(
                    ['fiscal_document_item_id' => $item->id],
                    [
                        'tenant_id' => $fiscal_document->tenant_id,
                        'expense_category_id' => $rule->expense_category_id,
                        'method' => ClassificationMethod::Rule,
                        'confidence' => 1.0,
                        'status' => ClassificationStatus::Suggested,
                        'rule_id' => $rule->id,
                        'llm_call_id' => null,
                        'prompt_version' => null,
                    ],
                );

                $hits[$rule->id] = ($hits[$rule->id] ?? 0) + 1;
                $classified++;

                continue;
            }

            // Solo se consulta la IA para ítems aún pendientes: una segunda
            // pasada no debe volver a gastar presupuesto ni pisar una revisión.
            if ($item->classification_status !== FiscalDocumentItem::STATUS_PENDING) {
                continue;
            }

            if (trim((string) $item->description) === '') {
                continue;
            }

            if ($categories === null) {
                $categories = $this->validCategories();
            }

            if ($categories === []) {
                continue;
            }

            if ($this->classifyWithAi($fiscal_document, $item, $categories)) {
                $classified++;
            }
        }

        foreach ($hits as $ruleId => $count) {
            ClassificationRule::withoutGlobalScopes()
                ->whereKey($ruleId)
                ->increment('hits_count', $count, ['last_matched_at' => now()]);
        }

        return $classified;
    }

    /**
     * @param  list<ClassificationCategory>  $categories
     */
    private function classifyWithAi(FiscalDocument $fiscal_document, FiscalDocumentItem $item, array $categories): bool
    {
        try {
            $result = $this->classifier->classify(new ClassificationRequest(
                description: (string) $item->description,
                // El comprobante no guarda el rubro del emisor; queda null
                // hasta que exista una fuente (F3-04/F4). El DTO ya lo admite.
                issuerActivity: null,
                categories: $categories,
            ));
        } catch (ClassifierUnavailable|InvalidClassifierOutput $exception) {
            Log::warning('classification.ai_unavailable', [
                'tenant_id' => $fiscal_document->tenant_id,
                'fiscal_document_item_id' => $item->id,
                'reason' => $exception::class,
            ]);

            $this->markForReview($item);

            return false;
        } catch (Throwable $exception) {
            // Último cortafuegos: un fallo inesperado del adaptador no debe
            // tumbar la importación del comprobante. El ítem va a revisión y
            // el detalle queda en el log sin datos del comprobante.
            Log::error('classification.ai_error', [
                'tenant_id' => $fiscal_document->tenant_id,
                'fiscal_document_item_id' => $item->id,
                'reason' => $exception::class,
            ]);

            $this->markForReview($item);

            return false;
        }

        $suggestion = $result->suggestion;

        if (! $suggestion instanceof ClassificationSuggestion) {
            return false;
        }

        $category = $this->categoryByCode($categories, $suggestion->categoryCode);

        if (! $category instanceof ClassificationCategory) {
            Log::warning('classification.ai_unknown_category', [
                'tenant_id' => $fiscal_document->tenant_id,
                'fiscal_document_item_id' => $item->id,
            ]);

            $this->markForReview($item);

            return false;
        }

        $accepted = $suggestion->confidence >= $this->confidenceThreshold;

        $item->forceFill([
            'expense_category_id' => $category->id,
            'classification_status' => $accepted ? FiscalDocumentItem::STATUS_AUTO : FiscalDocumentItem::STATUS_REVIEW,
        ])->save();

        Classification::query()->updateOrCreate(
            ['fiscal_document_item_id' => $item->id],
            [
                'tenant_id' => $fiscal_document->tenant_id,
                'expense_category_id' => $category->id,
                'method' => $result->cached ? ClassificationMethod::Cache : ClassificationMethod::Llm,
                'confidence' => $suggestion->confidence,
                'status' => $accepted ? ClassificationStatus::Suggested : ClassificationStatus::NeedsReview,
                'rule_id' => null,
                'llm_call_id' => $result->llmCallId,
                'prompt_version' => $result->promptVersion,
            ],
        );

        return true;
    }

    /**
     * @param  list<ClassificationCategory>  $categories
     */
    private function categoryByCode(array $categories, string $code): ?ClassificationCategory
    {
        foreach ($categories as $category) {
            if ($category->code === $code) {
                return $category;
            }
        }

        return null;
    }

    private function markForReview(FiscalDocumentItem $item): void
    {
        $item->forceFill([
            'classification_status' => FiscalDocumentItem::STATUS_REVIEW,
        ])->save();
    }

    /**
     * @return list<ClassificationCategory>
     */
    private function validCategories(): array
    {
        /** @var list<ClassificationCategory> $categories */
        $categories = [];

        $rows = ExpenseCategory::query()
            ->currentlyValid()
            ->orderBy('display_order')
            ->orderBy('code')
            ->get();

        foreach ($rows as $category) {
            $categories[] = new ClassificationCategory(
                id: (int) $category->id,
                code: (string) $category->code,
                name: (string) $category->name,
            );
        }

        return $categories;
    }
}
