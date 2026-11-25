<?php

declare(strict_types=1);

namespace App\Http\Requests\Fiscal;

use App\Domain\Fiscal\Models\FiscalSignoffReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreFiscalSignoffReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reviewAny', FiscalSignoffReview::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['approved', 'changes_requested'])],
            'stage_version' => ['required', 'integer', 'min:1'],
            'stage_fingerprint' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'comments' => [
                Rule::requiredIf($this->input('decision') === 'changes_requested'),
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }
}
