<?php

declare(strict_types=1);

namespace App\Http\Requests\Classification;

use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Validation\ValidMatchPattern;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateClassificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $rule = $this->route('rule');

        return $user instanceof User
            && $rule instanceof ClassificationRule
            && $user->can('update', $rule);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $routeRule = $this->route('rule');
        $matchType = $this->input(
            'match_type',
            $routeRule instanceof ClassificationRule ? $routeRule->match_type : null,
        );

        if ($matchType instanceof MatchType) {
            $matchType = $matchType->value;
        }

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'priority' => ['sometimes', 'integer', 'between:0,1000'],
            'match_type' => ['sometimes', 'required', Rule::enum(MatchType::class)],
            'pattern' => ['sometimes', 'required', 'string', 'max:255', new ValidMatchPattern(is_string($matchType) ? $matchType : null)],
            'expense_category_id' => ['sometimes', 'required', 'integer', Rule::exists('expense_categories', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'confirm_conflict' => ['sometimes', 'boolean'],
        ];
    }
}
