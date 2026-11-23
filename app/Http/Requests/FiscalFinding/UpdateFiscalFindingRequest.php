<?php

declare(strict_types=1);

namespace App\Http\Requests\FiscalFinding;

use App\Domain\Fiscal\Enums\FiscalRiskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateFiscalFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $dismissing = $this->input('status') === FiscalRiskStatus::Dismissed->value;

        return [
            'status' => ['required', 'string', Rule::in([
                FiscalRiskStatus::Acknowledged->value,
                FiscalRiskStatus::Resolved->value,
                FiscalRiskStatus::Dismissed->value,
            ])],
            // Descartar exige justificación: el hallazgo deja de mostrarse y el
            // motivo queda en la auditoría del comprobante.
            'note' => [
                'nullable',
                'string',
                'max:500',
                Rule::requiredIf($dismissing),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Indica el motivo para descartar el hallazgo.',
        ];
    }
}
