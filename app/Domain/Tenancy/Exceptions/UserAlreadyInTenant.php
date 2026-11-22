<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * El usuario ya pertenece al estudio: no se puede invitar de nuevo.
 */
final class UserAlreadyInTenant extends RuntimeException
{
    public function __construct(string $email)
    {
        parent::__construct("El usuario {$email} ya pertenece al estudio.");
    }
}