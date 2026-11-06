<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

final readonly class LoginUser
{
    public function handle(string $email, string $password, bool $remember = false): ?User
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User || ! Hash::check($password, (string) $user->password)) {
            return null;
        }

        return $user;
    }
}
