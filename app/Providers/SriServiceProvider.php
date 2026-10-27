<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Sri\Actions\ImportSriRun;
use App\Domain\Sri\Actions\ParseSriRun;
use App\Domain\Sri\Actions\PersistSriInvoice;
use App\Domain\Sri\Actions\ReconcileSriRun;
use App\Domain\Sri\Contracts\SriAuthorizationGateway;
use App\Domain\Sri\Contracts\SriInvoiceParser;
use App\Domain\Sri\Contracts\SriRobotGateway;
use App\Domain\Sri\Contracts\SriRobotObjectStore;
use App\Domain\Sri\Parsers\PeriodBatchBuilder;
use App\Integrations\Sri\FakeSriAuthorizationGateway;
use App\Integrations\Sri\FakeSriRobotGateway;
use App\Integrations\Sri\FilesystemSriRobotObjectStore;
use App\Integrations\Sri\LambdaSriRobotGateway;
use App\Integrations\Sri\PackageSriAuthorizationGateway;
use App\Integrations\Sri\PackageSriInvoiceParser;
use Aws\Lambda\LambdaClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use JulioSerpone\SriComprobantes\Client\AutorizacionClient;
use JulioSerpone\SriComprobantes\Config\SriEnvironment;
use JulioSerpone\SriComprobantes\Config\SriService;
use JulioSerpone\SriComprobantes\Retry\RetryPolicy;
use JulioSerpone\SriComprobantes\Transport\SoapTransport;

/**
 * Construye los adaptadores de SRI sin acoplar el dominio a SOAP ni a AWS.
 */
final class SriServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SriAuthorizationGateway::class, function (Application $app): SriAuthorizationGateway {
            $driver = (string) config('sri.authorization.driver', 'package');

            if ($driver === 'fake') {
                return new FakeSriAuthorizationGateway;
            }

            if ($driver !== 'package') {
                throw new InvalidArgumentException("Driver de consulta SRI no soportado: {$driver}.");
            }

            $environment = SriEnvironment::fromCode((string) config('sri.authorization.environment', '1'));

            $transport = new SoapTransport(
                wsdl: $environment->wsdl(SriService::AUTORIZACION),
                connectionTimeout: (float) config('sri.authorization.connection_timeout', 10),
                timeout: (float) config('sri.authorization.timeout', 30),
            );

            $retry = new RetryPolicy(
                maxAttempts: (int) config('sri.authorization.retry.attempts', 3),
                baseDelayMs: (int) config('sri.authorization.retry.base_delay_ms', 250),
                maxDelayMs: (int) config('sri.authorization.retry.max_delay_ms', 5000),
            );

            return new PackageSriAuthorizationGateway(
                new AutorizacionClient($transport),
                $retry,
            );
        });

        $this->app->singleton(SriInvoiceParser::class, function (Application $app): SriInvoiceParser {
            return new PackageSriInvoiceParser;
        });

        $this->app->singleton(SriRobotGateway::class, function (Application $app): SriRobotGateway {
            $driver = (string) config('sri.robot.driver', 'fake');

            if ($driver === 'fake') {
                return new FakeSriRobotGateway;
            }

            if ($driver !== 'lambda') {
                throw new InvalidArgumentException("Driver de robot SRI no soportado: {$driver}.");
            }

            return new LambdaSriRobotGateway(
                new LambdaClient([
                    'version' => 'latest',
                    'region' => (string) config('sri.robot.region', 'us-east-1'),
                ]),
                (string) config('sri.robot.function', 'sri-txt-robot'),
                (string) config('sri.robot.public_key', ''),
                (string) config('sri.robot.callback_url', ''),
            );
        });

        $this->app->singleton(SriRobotObjectStore::class, function (Application $app): SriRobotObjectStore {
            return new FilesystemSriRobotObjectStore(
                Storage::disk((string) config('sri.robot.output_disk', 'sri')),
            );
        });

        $this->app->singleton(ReconcileSriRun::class, function (Application $app): ReconcileSriRun {
            return new ReconcileSriRun(
                $app->make(SriRobotObjectStore::class),
                (string) config('sri.robot.environment', 'dev'),
            );
        });

        $this->app->singleton(PersistSriInvoice::class, fn (): PersistSriInvoice => new PersistSriInvoice);

        $this->app->singleton(ImportSriRun::class, function (Application $app): ImportSriRun {
            return new ImportSriRun(
                $app->make(SriAuthorizationGateway::class),
                $app->make(SriInvoiceParser::class),
                $app->make(PersistSriInvoice::class),
                $app->make(ParseSriRun::class),
            );
        });

        $this->app->singleton(ParseSriRun::class, function (Application $app): ParseSriRun {
            return new ParseSriRun(
                $app->make(SriRobotObjectStore::class),
                new PeriodBatchBuilder,
                $app->make(ReconcileSriRun::class),
                (string) config('sri.robot.environment', 'dev'),
            );
        });
    }
}
