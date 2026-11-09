<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Companies\Models\SriCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representación pública de una credencial SRI.
 *
 * Nunca incluye la contraseña ni el blob cifrado; `has_password` es siempre
 * `true` porque la fila existe, y sirve para que el frontend muestre el estado
 * sin tener que inferirlo.
 *
 * @property-read SriCredential $resource
 */
final class SriCredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $credential = $this->resource;

        return [
            'id' => $credential->public_id,
            'company_id' => $credential->company?->public_id,
            'username' => $credential->username,
            'has_password' => true,
            'status' => $credential->status,
            'failure_count' => $credential->failure_count,
            'last_used_at' => $credential->last_used_at?->toIso8601String(),
            'last_success_at' => $credential->last_success_at?->toIso8601String(),
            'last_failure_at' => $credential->last_failure_at?->toIso8601String(),
            'rotated_at' => $credential->rotated_at?->toIso8601String(),
            'created_at' => $credential->created_at?->toIso8601String(),
            'updated_at' => $credential->updated_at?->toIso8601String(),
        ];
    }
}
