<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Companies\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representación pública de una empresa.
 *
 * Expone `public_id` como identificador y omite `tenant_id` e `id` internos.
 *
 * @property-read Company $resource
 */
final class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $company = $this->resource;

        return [
            'id' => $company->public_id,
            'ruc' => $company->ruc,
            'legal_name' => $company->legal_name,
            'trade_name' => $company->trade_name,
            'regime' => $company->regime->value,
            'regime_label' => $company->regime->label(),
            'is_accounting_required' => $company->is_accounting_required,
            'is_withholding_agent' => $company->is_withholding_agent,
            'withholding_resolution' => $company->withholding_resolution,
            'economic_activity_code' => $company->economic_activity_code,
            'address' => $company->address,
            'email' => $company->email,
            'phone' => $company->phone,
            'fiscal_year_start_month' => $company->fiscal_year_start_month,
            'status' => $company->status,
            'settings' => $company->settings,
            'created_at' => $company->created_at?->toIso8601String(),
            'updated_at' => $company->updated_at?->toIso8601String(),
        ];
    }
}
