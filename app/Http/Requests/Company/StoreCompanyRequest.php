<?php

declare(strict_types=1);

namespace App\Http\Requests\Company;

use App\Domain\Companies\Enums\TaxRegime;
use App\Domain\Companies\Models\Company;
use App\Domain\Shared\Validation\ValidRuc;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', Company::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ruc' => [
                'required',
                'string',
                'size:13',
                new ValidRuc,
                Rule::unique('companies', 'ruc')->where(
                    fn (Builder $query): Builder => $query->where(
                        'tenant_id',
                        app(TenantContext::class)->id(),
                    ),
                ),
            ],
            'legal_name' => ['required', 'string', 'max:300'],
            'trade_name' => ['nullable', 'string', 'max:300'],
            'regime' => ['sometimes', 'string', Rule::enum(TaxRegime::class)],
            'is_accounting_required' => ['sometimes', 'boolean'],
            'is_withholding_agent' => ['sometimes', 'boolean'],
            'withholding_resolution' => [
                'nullable',
                'string',
                'max:64',
                Rule::requiredIf(fn (): bool => $this->boolean('is_withholding_agent')),
            ],
            'economic_activity_code' => ['nullable', 'string', 'max:10'],
            'address' => ['nullable', 'string', 'max:300'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'fiscal_year_start_month' => ['sometimes', 'integer', 'between:1,12'],
            'settings' => ['sometimes', 'array'],
        ];
    }
}
