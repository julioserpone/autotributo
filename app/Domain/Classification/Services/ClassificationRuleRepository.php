<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Models\ClassificationRule;
use Illuminate\Support\Facades\Cache;

/**
 * Cache del catálogo de reglas activas.
 *
 * Reglas y patrones cambian poco y se consultan en cada ítem importado, así
 * que se guardan por tenant. La versión se incrementa con cada alta, cambio o
 * borrado (`ClassificationRule::booted`), lo que invalida las entradas sin
 * recorrer claves.
 *
 * En modo single (ADR-0001) solo hay un tenant por despliegue; si se activa
 * multi-tenancy, la versión debe promoverse a una clave global para invalidar
 * también a los demás estudios cuando cambie una regla global.
 */
final class ClassificationRuleRepository
{
    private const VERSION_KEY = 'classification-rules:version';

    private const TTL_MINUTES = 10;

    /**
     * Reglas activas ordenadas por precedencia: prioridad ascendente, regla
     * del tenant antes que la global y, por último, la más antigua.
     *
     * @return list<ClassificationRule>
     */
    public function forTenant(int $tenantId): array
    {
        /** @var list<ClassificationRule> $rules */
        $rules = Cache::remember(
            $this->key($tenantId),
            now()->addMinutes(self::TTL_MINUTES),
            fn (): array => array_values(ClassificationRule::query()
                ->where('is_active', true)
                ->orderBy('priority')
                ->orderByRaw('tenant_id is null')
                ->orderBy('id')
                ->get()
                ->all()),
        );

        return $rules;
    }

    /**
     * Invalida todas las entradas de reglas.
     */
    public static function flush(): void
    {
        $current = Cache::get(self::VERSION_KEY, 1);

        Cache::forever(self::VERSION_KEY, is_numeric($current) ? (int) $current + 1 : 1);
    }

    private function key(int $tenantId): string
    {
        $version = Cache::get(self::VERSION_KEY, 1);

        return sprintf(
            'classification-rules:%d:%d',
            $tenantId,
            is_numeric($version) ? (int) $version : 1,
        );
    }
}
