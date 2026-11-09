<?php

declare(strict_types=1);

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\Models\AuditLog;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Auditoría de cambios de un modelo sensible.
 *
 * Envuelve el trait del paquete para fijar el modelo de auditoría propio
 * (`AuditLog`, con `tenant_id` y redacción) y para excluir los atributos que
 * nunca deben quedar en el historial.
 *
 * El paquete registra su observer para **todos** los modelos, así que cada
 * modelo auditable debe implementar `AuditableContract`; de lo contrario el
 * observer recibe un modelo que no cumple el tipo y falla en `created`.
 *
 * @phpstan-ignore trait.unused
 */
trait Auditable
{
    use AuditableTrait;

    /**
     * @return array<string, mixed>
     */
    public function transformAudit(array $data): array
    {
        if ($this instanceof AuditableContract) {
            $data['old_values'] = AuditLog::redact($data['old_values'] ?? null);
            $data['new_values'] = AuditLog::redact($data['new_values'] ?? null);
        }

        return $data;
    }
}
