<?php

declare(strict_types=1);

namespace App\Http\Requests\Classification;

use App\Domain\Classification\Enums\ReviewDecision;
use App\Domain\Classification\Models\Classification;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Decisión de revisión de una clasificación (F3-05).
 *
 * `expense_category_id` solo acompaña a `accept`: permite corregir la
 * categoría sugerida. Al rechazar se prohíbe, porque el ítem queda sin
 * categoría a propósito.
 */
final class ReviewClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $classification = $this->route('classification');

        return $user instanceof User
            && $classification instanceof Classification
            && $user->can('review', $classification);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(ReviewDecision::class)],
            'expense_category_id' => [
                'nullable',
                'integer',
                'prohibited_if:decision,'.ReviewDecision::Reject->value,
                Rule::exists('expense_categories', 'id')->where(
                    fn ($query) => $query
                        ->where('valid_from', '<=', now()->toDateString())
                        ->where(function ($nested): void {
                            $nested->whereNull('valid_to')
                                ->orWhere('valid_to', '>=', now()->toDateString());
                        }),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expense_category_id.prohibited_if' => 'Al rechazar no se envía una categoría.',
            'expense_category_id.exists' => 'La categoría no existe o no está vigente.',
        ];
    }
}
