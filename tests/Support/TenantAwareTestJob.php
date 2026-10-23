<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tenancy\Jobs\Concerns\TenantAware;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Job de prueba: registra el tenant con el que se ejecutó.
 */
final class TenantAwareTestJob implements ShouldQueue
{
    use TenantAware;

    /** @var list<array{tenant_id: int, marker: string}> */
    public static array $handled = [];

    public function __construct(public readonly string $marker) {}

    protected function handleInTenant(TenantContext $context): void
    {
        self::$handled[] = [
            'tenant_id' => $context->id(),
            'marker' => $this->marker,
        ];
    }
}
