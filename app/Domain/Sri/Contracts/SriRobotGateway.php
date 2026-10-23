<?php

declare(strict_types=1);

namespace App\Domain\Sri\Contracts;

use App\Domain\Sri\Data\SriRobotDispatch;
use App\Domain\Sri\Data\SriRobotRequest;

/**
 * Puerto de invocación del robot que descarga los TXT del portal del SRI.
 */
interface SriRobotGateway
{
    public function dispatch(SriRobotRequest $request): SriRobotDispatch;
}
