<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Classification\Actions\SyncClassificationRules;
use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Models\ClassificationRule;
use Illuminate\Console\Command;

/**
 * Carga y activa las reglas base para emisores recurrentes.
 *
 * Las reglas se siembran inactivas a propósito (`db:seed` no cambia la
 * clasificación de un contribuyente). Este comando es la vía explícita para
 * activarlas cuando el estudio ya las revisó.
 *
 * Uso: `php artisan classification:seed-rules` (solo carga)
 *      `php artisan classification:seed-rules --activate` (activa todas)
 *      `php artisan classification:seed-rules --ruc=1790016919001` (solo ese RUC)
 *      `php artisan classification:seed-rules --deactivate` (las desactiva)
 */
final class SeedClassificationRulesCommand extends Command
{
    protected $signature = 'classification:seed-rules
        {--activate : Activa las reglas base (todas o las del --ruc)}
        {--ruc= : Limita la activación o desactivación a un RUC}
        {--deactivate : Desactiva las reglas base}
        {--list : Lista las reglas base con su estado}';

    protected $description = 'Carga, lista y activa o desactiva las reglas base de emisores recurrentes';

    public function handle(SyncClassificationRules $sync): int
    {
        if (! $this->option('list')) {
            $counts = $sync->handle();

            $this->line(sprintf(
                'Reglas base: %d creadas, %d actualizadas, %d sin cambios, %d omitidas.',
                $counts['created'],
                $counts['updated'],
                $counts['unchanged'],
                $counts['skipped'],
            ));
        }

        $ruc = $this->option('ruc');
        $ruc = is_string($ruc) && $ruc !== '' ? $ruc : null;

        if ((bool) $this->option('activate')) {
            $this->setActive($ruc, true);
        }

        if ((bool) $this->option('deactivate')) {
            $this->setActive($ruc, false);
        }

        $this->listRules();

        return self::SUCCESS;
    }

    private function setActive(?string $ruc, bool $active): void
    {
        $query = ClassificationRule::query()
            ->withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('match_type', MatchType::ProviderRuc->value);

        if ($ruc !== null) {
            $query->where('pattern', $ruc);
        }

        // Se guarda modelo a modelo a propósito: un `update()` masivo no dispara
        // los eventos que invalidan la caché del catálogo de reglas.
        $affected = 0;

        foreach ($query->get() as $rule) {
            if ($rule->is_active === $active) {
                continue;
            }

            $rule->is_active = $active;
            $rule->save();
            $affected++;
        }

        $this->info(sprintf('%d regla(s) %s.', $affected, $active ? 'activadas' : 'desactivadas'));
    }

    private function listRules(): void
    {
        $rules = ClassificationRule::query()
            ->withoutGlobalScopes()
            ->with('expenseCategory')
            ->whereNull('tenant_id')
            ->where('match_type', MatchType::ProviderRuc->value)
            ->orderBy('priority')
            ->get();

        if ($rules->isEmpty()) {
            $this->warn('No hay reglas base cargadas.');

            return;
        }

        $this->table(
            ['RUC', 'Proveedor', 'Categoría', 'Activa'],
            $rules->map(static fn (ClassificationRule $rule): array => [
                $rule->pattern,
                $rule->name,
                $rule->expenseCategory->name ?? '—',
                $rule->is_active ? 'sí' : 'no',
            ])->all(),
        );
    }
}
