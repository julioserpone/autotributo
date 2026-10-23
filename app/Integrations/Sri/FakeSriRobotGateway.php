<?php

declare(strict_types=1);

namespace App\Integrations\Sri;

use App\Domain\Sri\Contracts\SriRobotGateway;
use App\Domain\Sri\Data\SriRobotDispatch;
use App\Domain\Sri\Data\SriRobotRequest;

/**
 * Doble determinista del robot para desarrollo local y pruebas.
 */
final class FakeSriRobotGateway implements SriRobotGateway
{
    /** @var list<SriRobotRequest> */
    public array $requests = [];

    public function dispatch(SriRobotRequest $request): SriRobotDispatch
    {
        $this->requests[] = $request;

        return new SriRobotDispatch(
            functionName: 'fake-sri-txt-robot',
            statusCode: 202,
            requestId: 'fake-'.count($this->requests),
        );
    }
}
