<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('hashea la contraseña y castea la verificación de email', function (): void {
    $user = User::factory()->create([
        'email' => 'ana@example.com',
        'password' => 'secret-password',
    ]);

    expect($user->email)->toBe('ana@example.com')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});
