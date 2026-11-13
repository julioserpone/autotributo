<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\Actions\ProvisionTenant;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Provisiona el estudio inicial de forma idempotente.
 *
 * En modo single el slug debe coincidir con TENANCY_DEFAULT_SLUG porque es
 * el que resuelve cada request. Repetir el comando no duplica tenant, roles,
 * owner ni membresía, y solo reenvía el enlace si se pide explícitamente.
 */
final class ProvisionCommand extends Command
{
    protected $signature = 'app:provision
                            {--name= : Nombre del estudio}
                            {--slug= : Slug del tenant (por defecto TENANCY_DEFAULT_SLUG)}
                            {--owner-email= : Correo del usuario owner}
                            {--owner-name= : Nombre visible del owner}
                            {--resend-invite : Reenvía el enlace de contraseña aunque el owner ya exista}
                            {--no-invite : No envía el enlace de contraseña}';

    protected $description = 'Provisiona el tenant por defecto, sus roles y el usuario owner';

    public function handle(ProvisionTenant $provision): int
    {
        $name = trim((string) $this->option('name'));
        $ownerEmail = trim((string) $this->option('owner-email'));
        $ownerName = trim((string) $this->option('owner-name'));

        if ($name === '') {
            $this->error('Indica el nombre del estudio con --name.');

            return self::FAILURE;
        }

        if (filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Indica un correo válido con --owner-email.');

            return self::FAILURE;
        }

        $mode = (string) config('tenancy.mode');
        $defaultSlug = (string) config('tenancy.default_slug');
        $slug = trim((string) $this->option('slug'));
        $slug = $slug !== '' ? $slug : $defaultSlug;

        if ($mode === 'single' && $slug !== $defaultSlug) {
            $this->error(
                "En TENANCY_MODE=single el slug debe coincidir con TENANCY_DEFAULT_SLUG ({$defaultSlug}).",
            );

            return self::FAILURE;
        }

        $sendInvite = ! (bool) $this->option('no-invite');
        $resendInvite = $sendInvite && (bool) $this->option('resend-invite');

        try {
            $result = $provision->handle(
                name: $name,
                slug: $slug,
                ownerEmail: $ownerEmail,
                ownerName: $ownerName !== '' ? $ownerName : null,
                resendInvite: $resendInvite,
                sendInvite: $sendInvite,
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $result->hasChanges()) {
            $this->info('Sin cambios.');
        } else {
            $this->info('Estudio provisionado.');
        }

        $this->line(sprintf('Tenant: %s (slug: %s, id: %d)', $result->tenant->name, $result->tenant->slug, $result->tenant->id));
        $this->line(sprintf('Owner: %s', $result->owner->email));
        $this->line(sprintf(
            'Cambios: tenant=%s, owner=%s, membership=%s',
            $result->tenantCreated ? 'creado' : 'existente',
            $result->ownerCreated ? 'creado' : 'existente',
            $result->membershipCreated ? 'creada' : 'existente',
        ));

        if ($sendInvite) {
            $this->line($result->ownerCreated || $resendInvite
                ? 'Enlace de contraseña enviado al owner.'
                : 'No se reenvió el enlace; usa --resend-invite para forzarlo.');
        }

        $this->line(sprintf(
            'Catálogos fiscales: %d filas (%d creadas, %d actualizadas, %d sin cambios).',
            $result->catalogs->total(),
            $result->catalogs->created(),
            $result->catalogs->updated(),
            $result->catalogs->unchanged(),
        ));

        return self::SUCCESS;
    }
}
