<?php

declare(strict_types=1);

namespace App\Integrations\Sri;

use App\Domain\Sri\Contracts\SriAuthorizationGateway;
use App\Domain\Sri\Data\AuthorizationQuery;
use App\Domain\Sri\Data\AuthorizationResult;

/**
 * Doble determinista para desarrollo local y pruebas.
 *
 * @phpstan-type Results array<string, AuthorizationResult>
 */
final class FakeSriAuthorizationGateway implements SriAuthorizationGateway
{
    /** @var list<AuthorizationQuery> */
    public array $queries = [];

    /**
     * @param  Results  $results
     */
    public function __construct(private readonly array $results = []) {}

    public function query(AuthorizationQuery $query): AuthorizationResult
    {
        $this->queries[] = $query;

        return $this->results[$query->accessKey]
            ?? AuthorizationResult::notFound($query->accessKey);
    }
}
