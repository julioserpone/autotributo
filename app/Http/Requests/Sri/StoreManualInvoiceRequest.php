<?php

declare(strict_types=1);

namespace App\Http\Requests\Sri;

use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida la carga manual de un XML autorizado (F2-13 / US-14).
 *
 * El límite de 2 MB se aplica antes de leer el contenido y la extensión se
 * restringe a XML; la guardia XXE y el parseo viven en la acción de dominio.
 */
final class StoreManualInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $company = $this->route('company');

        if (! $user instanceof User || ! $company instanceof Company) {
            return false;
        }

        return RolePermissions::allows($user, Ability::SyncStart)
            && RolePermissions::sameTenant($company);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:2048', 'mimes:xml'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Debe adjuntar un XML.',
            'file.file' => 'El archivo adjunto no es válido.',
            'file.max' => 'El XML no puede superar los 2 MB.',
            'file.mimes' => 'El archivo debe ser un XML.',
        ];
    }
}
