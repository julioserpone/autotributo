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
        return [
            'status' => ['required', 'string', Rule::in([
                FiscalRiskStatus::Acknowledged->value,
                FiscalRiskStatus::Resolved->value,
                FiscalRiskStatus::Dismissed->value,
            ])],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
