<?php

declare(strict_types=1);

namespace App\Http\Requests\Sri;

use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inicia una sincronización del robot para una empresa del tenant activo.
 *
 * `Idempotency-Key` es obligatoria: la regla de oro es que reintentar un POST
 * no consuma cuota ni cree runs duplicados (docs/01 §10).
 */
final class StoreSyncRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user()?->can('create', SriSyncRun::class) !== true) {
            return false;
        }

        // La clave de idempotencia es obligatoria: sin ella un reintento
        // podría duplicar invocaciones del robot y consumir cuota.
        return $this->idempotencyKey() !== '' && strlen($this->idempotencyKey()) <= 64;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [
                'required',
                'string',
                Rule::exists('companies', 'public_id')->where('tenant_id', app(TenantContext::class)->id()),
            ],
            'periods' => ['required', 'array', 'min:1', 'max:12'],
            'periods.*' => ['string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'document_types' => ['sometimes', 'array', 'min:1'],
            'document_types.*' => ['string', Rule::in(SriDocumentType::codes())],
            'force' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'periods.*.regex' => 'Cada período debe tener el formato AAAA-MM.',
            'document_types.*.in' => 'Tipo de comprobante no soportado.',
        ];
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
    }
}
