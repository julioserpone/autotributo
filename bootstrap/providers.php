<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\CompaniesServiceProvider;
use App\Providers\SriServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    AuditServiceProvider::class,
    BroadcastServiceProvider::class,
    TenancyServiceProvider::class,
    CompaniesServiceProvider::class,
    SriServiceProvider::class,
];
