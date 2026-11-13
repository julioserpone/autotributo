<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Data;

use App\Domain\Fiscal\Data\CatalogSyncResult;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;

final readonly class ProvisionResult
{
    public function __construct(
        public Tenant $tenant,
        public User $owner,
        public bool $tenantCreated,
        public bool $ownerCreated,
        public bool $membershipCreated,
        public CatalogSyncResult $catalogs,
    ) {}

    public function hasChanges(): bool
    {
        return $this->tenantCreated
            || $this->ownerCreated
            || $this->membershipCreated
            || $this->catalogs->hasChanges();
    }
}
