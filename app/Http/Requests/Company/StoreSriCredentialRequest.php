<?php

declare(strict_types=1);

namespace App\Http\Requests\Company;

use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\Permissions\RolePermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Valida la carga y la rotación de credenciales del portal.
 *
 * El nombre de usuario debe ser el RUC de la empresa: el robot descarga en el
 * contexto de ese contribuyente y una credencial cruzada solo produce errores
 * opacos en el portal. La contraseña nunca se registra ni se devuelve.
 */
final class StoreSriCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        // Una empresa de otro tenant responde 404, no 403: el binding no la
        // resolvió bajo el scope activo, así que aquí simplemente no autoriza.
        if (! $company instanceof Company || ! RolePermissions::sameTenant($company)) {
            abort(404);
        }

        return $this->user()?->can('credentials.update', $company) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'regex:/^\d{10}(\d{3})?$/'],
            'password' => ['required', 'string', 'min:6', 'max:255', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => 'El usuario debe ser un RUC de 13 dígitos o una cédula de 10.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $company = $this->route('company');

                if (! $company instanceof Company) {
                    return;
                }

                if ($this->string('username')->toString() !== $company->ruc) {
                    $validator->errors()->add(
                        'username',
                        'El usuario del portal debe coincidir con el RUC de la empresa.',
                    );
                }
            },
        ];
    }
}
