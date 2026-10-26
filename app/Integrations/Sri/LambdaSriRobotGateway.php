<?php

declare(strict_types=1);

namespace App\Integrations\Sri;

use App\Domain\Sri\Contracts\SriRobotGateway;
use App\Domain\Sri\Data\SriRobotCredentials;
use App\Domain\Sri\Data\SriRobotDispatch;
use App\Domain\Sri\Data\SriRobotRequest;
use App\Domain\Sri\Exceptions\SriGatewayException;
use Aws\Exception\AwsException;
use Aws\Lambda\LambdaClient;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use SodiumException;

/**
 * Invoca `sri-txt-robot` de forma asíncrona (`InvocationType=Event`).
 *
 * El robot descarga los TXT y escribe sus resultados en el S3 configurado en
 * la propia función Lambda. Si la solicitud lleva credenciales, aquí se
 * sellan con `sodium_crypto_box_seal` y la clave pública del robot; la
 * contraseña nunca se envía en claro.
 */
final readonly class LambdaSriRobotGateway implements SriRobotGateway
{
    public function __construct(
        private LambdaClient $lambda,
        private string $functionName,
        private ?string $publicKey = null,
        private string $callbackUrl = '',
    ) {
        if (trim($this->functionName) === '') {
            throw new InvalidArgumentException('El nombre de la función Lambda del robot es obligatorio.');
        }
    }

    public function dispatch(SriRobotRequest $request): SriRobotDispatch
    {
        // El callback asíncrono necesita un identificador que la app pueda
        // correlacionar con el run. Se genera aquí y viaja en el payload.
        $eventId = (string) Str::ulid();

        try {
            $sealedCredentials = $request->credentials !== null
                ? $this->sealCredentials($request->credentials)
                : null;

            $payload = array_merge(
                $request->toPayload($sealedCredentials),
                array_filter(
                    [
                        'event_id' => $eventId,
                        'callback_url' => trim($this->callbackUrl),
                    ],
                    static fn (string $valor): bool => $valor !== '',
                ),
            );

            $result = $this->lambda->invoke([
                'FunctionName' => $this->functionName,
                'InvocationType' => 'Event',
                'LogType' => 'None',
                'Payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
        } catch (SriGatewayException $exception) {
            throw $exception;
        } catch (JsonException|AwsException|SodiumException $exception) {
            throw SriGatewayException::robotInvocationFailed($this->functionName, $exception);
        }

        $requestId = $result['ResponseMetadata']['RequestId'] ?? null;

        return new SriRobotDispatch(
            functionName: $this->functionName,
            statusCode: (int) ($result['StatusCode'] ?? 0),
            requestId: is_string($requestId) ? $requestId : null,
            eventId: $eventId,
        );
    }

    /**
     * Cifra el contenido con la clave pública del robot.
     *
     * La clave se acepta en base64 puro (como la genera el robot) o con el
     * prefijo `base64:` habitual de Laravel.
     *
     * @throws SriGatewayException
     * @throws JsonException
     * @throws SodiumException
     */
    private function sealCredentials(SriRobotCredentials $credentials): string
    {
        $publicKey = trim((string) $this->publicKey);

        if (str_starts_with($publicKey, 'base64:')) {
            $publicKey = substr($publicKey, 7);
        }

        $decodedKey = base64_decode($publicKey, true);

        if ($decodedKey === false || strlen($decodedKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw SriGatewayException::robotCredentialsUnavailable();
        }

        $plaintext = json_encode($credentials->toSealingPayload(), JSON_THROW_ON_ERROR);

        return base64_encode(sodium_crypto_box_seal($plaintext, $decodedKey));
    }
}
