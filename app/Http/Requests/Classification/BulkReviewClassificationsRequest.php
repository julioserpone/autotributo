<?php

declare(strict_types=1);

namespace App\Http\Requests\Classification;

use App\Domain\Classification\Enums\ReviewDecision;
use App\Domain\Classification\Models\Classification;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Revisión masiva (F3-05).
 *
 * El lote se limita a 100 ids para que una acción de UI no se convierta en
 * una transacción larga ni en un pico de auditoría.
 */
final class BulkReviewClassificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->can('reviewAny', Classification::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
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
            'ids.max' => 'No se pueden revisar más de 100 clasificaciones a la vez.',
            'expense_category_id.prohibited_if' => 'Al rechazar no se envía una categoría.',
            'expense_category_id.exists' => 'La categoría no existe o no está vigente.',
        ];
    }
}
