<?php

declare(strict_types=1);

namespace App\Http\Requests\Classification;

use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Validation\ValidMatchPattern;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criterios del dry-run: una regla existente (`rule_id`) o una candidata.
 */
final class PreviewClassificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('viewAny', ClassificationRule::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $matchType = $this->input('match_type');

        return [
            'rule_id' => [
                'nullable',
                'integer',
                Rule::exists('classification_rules', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->whereNull('deleted_at')
                        ->where(function (Builder $tenantQuery): void {
                            $tenantQuery
                                ->where('tenant_id', app(TenantContext::class)->id())
                                ->orWhereNull('tenant_id');
                        }),
                ),
            ],
            'name' => ['sometimes', 'string', 'max:120'],
            'match_type' => ['required_without:rule_id', 'nullable', Rule::enum(MatchType::class)],
            'pattern' => [
                'required_without:rule_id',
                'nullable',
                'string',
                'max:255',
                new ValidMatchPattern(is_string($matchType) ? $matchType : null),
            ],
            'priority' => ['sometimes', 'integer', 'between:0,1000'],
            'company_id' => [
                'nullable',
                'string',
                Rule::exists('companies', 'public_id')->where(
                    fn (Builder $query): Builder => $query->where(
                        'tenant_id',
                        app(TenantContext::class)->id(),
                    ),
                ),
            ],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }
}
