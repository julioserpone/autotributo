<?php

declare(strict_types=1);

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Registra un evento que el ORM no puede observar por sí solo.
 *
 * Casos típicos: login/logout, aceptación de invitación, cambio de contraseña
 * y acceso a credenciales. El `tenant_id` se toma del contexto activo y el
 * actor del guard `web`; ambos son opcionales porque hay eventos globales.
 */
final readonly class RecordAudit
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function handle(
        string $event,
        Model $auditable,
        array $oldValues = [],
        array $newValues = [],
        ?int $userId = null,
        ?int $tenantId = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'tenant_id' => $tenantId ?? $this->tenantId(),
            'user_id' => $userId ?? Auth::id(),
            'event' => $event,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'old_values' => AuditLog::redact($oldValues),
            'new_values' => AuditLog::redact($newValues),
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 1000),
            'url' => substr(request()->fullUrl(), 0, 2048),
        ]);
    }

    private function tenantId(): ?int
    {
        try {
            return app(TenantContext::class)->id();
        } catch (Throwable) {
            return null;
        }
    }
}
