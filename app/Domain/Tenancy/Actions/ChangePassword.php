<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Audit\Actions\RecordAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final readonly class ChangePassword
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * Cambia la contraseña del usuario y revoca las demás sesiones activas.
     *
     * @throws \InvalidArgumentException si la contraseña actual no coincide.
     */
    public function handle(User $user, string $currentPassword, string $newPassword, ?string $currentSessionId = null): void
    {
        if (! Hash::check($currentPassword, (string) $user->password)) {
            throw new \InvalidArgumentException('La contraseña actual no es válida.');
        }

        $user->password = Hash::make($newPassword);
        $user->save();

        if ($currentSessionId !== null) {
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->where('id', '!=', $currentSessionId)
                ->delete();
        }

        $this->audit->handle('password_changed', $user, [], ['password' => '[redacted]']);
    }
}
