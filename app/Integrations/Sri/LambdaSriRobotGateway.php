<?php

declare(strict_types=1);

namespace App\Integrations\Sri;

use App\Domain\Sri\Contracts\SriRobotGateway;
use App\Domain\Sri\Data\SriRobotDispatch;
use App\Domain\Sri\Data\SriRobotRequest;
use App\Domain\Sri\Exceptions\SriGatewayException;
use Aws\Exception\AwsException;
use Aws\Lambda\LambdaClient;
use InvalidArgumentException;
use JsonException;

/**
 * Invoca `sri-txt-robot` de forma asíncrona (`InvocationType=Event`).
 *
 * El robot descarga los TXT y escribe sus resultados en el S3 configurado en
 * la propia función Lambda. Laravel solo envía RUC, períodos y tipos; nunca
 * credenciales del contribuyente.
 */
final readonly class LambdaSriRobotGateway implements SriRobotGateway
{
    public function __construct(
        private LambdaClient $lambda,
        private string $functionName,
    ) {
        if (trim($this->functionName) === '') {
            throw new InvalidArgumentException('El nombre de la función Lambda del robot es obligatorio.');
        }
    }

    public function dispatch(SriRobotRequest $request): SriRobotDispatch
    {
        try {
            $payload = json_encode($request->toPayload(), JSON_THROW_ON_ERROR);

            $result = $this->lambda->invoke([
                'FunctionName' => $this->functionName,
                'InvocationType' => 'Event',
                'LogType' => 'None',
                'Payload' => $payload,
            ]);
        } catch (JsonException|AwsException $exception) {
            throw SriGatewayException::robotInvocationFailed($this->functionName, $exception);
        }

        $requestId = $result['ResponseMetadata']['RequestId'] ?? null;

        return new SriRobotDispatch(
            functionName: $this->functionName,
            statusCode: (int) ($result['StatusCode'] ?? 0),
            requestId: is_string($requestId) ? $requestId : null,
        );
    }
}
