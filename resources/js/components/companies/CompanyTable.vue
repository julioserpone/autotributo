<script setup lang="ts">


import type { Company } from '@/stores/company';

defineProps<{
    companies: Company[];
    loading?: boolean;
    canManage?: boolean;
    canArchive?: boolean;
}>();

const emit = defineEmits<{
    (e: 'edit', company: Company): void;
    (e: 'archive', company: Company): void;
    (e: 'credential', company: Company): void;
}>();

function statusLabel(status: string): string {
    switch (status) {
        case 'active':
            return 'Activa';
        case 'inactive':
            return 'Inactiva';
        case 'archived':
            return 'Archivada';
        default:
            return status;
    }
}

function statusColor(status: string): 'success' | 'warning' | 'neutral' {
    switch (status) {
        case 'active':
            return 'success';
        case 'inactive':
            return 'warning';
        default:
            return 'neutral';
    }
}
</script>

<template>
  <section class="overflow-x-auto rounded-lg border border-default bg-elevated/50">
    <div
      v-if="loading"
      class="flex items-center justify-center gap-2 p-8 text-sm text-muted"
    >
      <UIcon
        name="i-lucide-loader-2"
        class="size-4 animate-spin"
      />
      Cargando contribuyentes…
    </div>

    <div
      v-else-if="companies.length === 0"
      class="p-8 text-center text-sm text-muted"
    >
      <UIcon
        name="i-lucide-building-2"
        class="mx-auto mb-2 size-8 text-dimmed"
      />
      <p class="font-medium text-highlighted">
        Aún no hay contribuyentes registrados
      </p>
      <p class="mt-1 text-xs text-dimmed">
        Registra el primer RUC para empezar a sincronizar y clasificar.
      </p>
    </div>

    <table
      v-else
      class="w-full text-sm"
    >
      <thead class="border-b border-default bg-elevated text-xs uppercase tracking-wide text-dimmed">
        <tr>
          <th class="px-4 py-2 text-left font-medium">
            RUC
          </th>
          <th class="px-4 py-2 text-left font-medium">
            Razón social
          </th>
          <th class="hidden px-4 py-2 text-left font-medium sm:table-cell">
            Nombre comercial
          </th>
          <th class="hidden px-4 py-2 text-left font-medium md:table-cell">
            Régimen
          </th>
          <th class="px-4 py-2 text-left font-medium">
            Estado
          </th>
          <th
            v-if="canManage || canArchive"
            class="px-4 py-2 text-right font-medium"
          >
            Acciones
          </th>
        </tr>
      </thead>
      <tbody class="divide-y divide-default">
        <tr
          v-for="company in companies"
          :key="company.id"
          class="transition hover:bg-elevated/60"
        >
          <td class="px-4 py-3 font-mono text-xs text-highlighted">
            {{ company.ruc }}
          </td>
          <td class="px-4 py-3 text-highlighted">
            {{ company.legal_name }}
          </td>
          <td class="hidden px-4 py-3 text-muted sm:table-cell">
            {{ company.trade_name || '—' }}
          </td>
          <td class="hidden px-4 py-3 text-muted md:table-cell">
            {{ company.regime_label }}
          </td>
          <td class="px-4 py-3">
            <UBadge
              :color="statusColor(company.status)"
              variant="soft"
              size="sm"
            >
              {{ statusLabel(company.status) }}
            </UBadge>
          </td>
          <td
            v-if="canManage || canArchive"
            class="px-4 py-3 text-right"
          >
            <div class="flex items-center justify-end gap-1">
              <UButton
                v-if="canManage"
                color="neutral"
                variant="ghost"
                size="xs"
                icon="i-lucide-key-round"
                aria-label="Gestionar credencial SRI"
                @click="emit('credential', company)"
              />
              <UButton
                v-if="canManage"
                color="neutral"
                variant="ghost"
                size="xs"
                icon="i-lucide-pencil"
                aria-label="Editar contribuyente"
                @click="emit('edit', company)"
              />
              <UButton
                v-if="canArchive"
                color="error"
                variant="ghost"
                size="xs"
                icon="i-lucide-archive"
                aria-label="Archivar contribuyente"
                @click="emit('archive', company)"
              />
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
