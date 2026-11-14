<?php

declare(strict_types=1);

namespace App\Http\Requests\Classification;

use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Validation\ValidMatchPattern;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreClassificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', ClassificationRule::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $matchType = $this->input('match_type');

        return [
            'name' => ['required', 'string', 'max:120'],
            'priority' => ['sometimes', 'integer', 'between:0,1000'],
            'match_type' => ['required', Rule::enum(MatchType::class)],
            'pattern' => ['required', 'string', 'max:255', new ValidMatchPattern(is_string($matchType) ? $matchType : null)],
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'confirm_conflict' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expense_category_id.exists' => 'La categoría de gasto no existe.',
            'match_type.enum' => 'El tipo de coincidencia no es válido.',
        ];
    }
}
