<script setup lang="ts">
import { useCompanyStore } from '@/stores/company';

const companyStore = useCompanyStore();

defineProps<{
    status: string;
    method: string;
    companyId: string;
    search: string;
}>();

const emit = defineEmits<{
    'update:status': [value: string];
    'update:method': [value: string];
    'update:companyId': [value: string];
    'update:search': [value: string];
    apply: [];
}>();
</script>

<template>
  <section class="rounded-lg border border-default bg-elevated/50 p-4">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <UFormField
        label="Estado"
        name="status"
      >
        <USelect
          :model-value="status"
          :items="[
            { label: 'Necesita revisión', value: 'needs_review' },
            { label: 'Sugerida', value: 'suggested' },
            { label: 'Aceptada', value: 'accepted' },
            { label: 'Rechazada', value: 'rejected' },
            { label: 'Todos', value: 'all' },
          ]"
          color="neutral"
          variant="outline"
          size="md"
          class="w-full"
          @update:model-value="(value: string) => { emit('update:status', value); emit('apply'); }"
        />
      </UFormField>

      <UFormField
        label="Método"
        name="method"
      >
        <USelect
          :model-value="method"
          :items="[
            { label: 'Todos', value: 'all' },
            { label: 'Regla', value: 'rule' },
            { label: 'Caché', value: 'cache' },
            { label: 'IA', value: 'llm' },
            { label: 'Manual', value: 'manual' },
          ]"
          color="neutral"
          variant="outline"
          size="md"
          class="w-full"
          @update:model-value="(value: string) => { emit('update:method', value); emit('apply'); }"
        />
      </UFormField>

      <UFormField
        label="Empresa"
        name="company"
      >
        <USelect
          :model-value="companyId"
          :items="[
            { label: 'Todas', value: 'all' },
            ...companyStore.companies.map((c) => ({ label: c.trade_name || c.legal_name, value: c.id })),
          ]"
          color="neutral"
          variant="outline"
          size="md"
          class="w-full"
          @update:model-value="(value: string) => { emit('update:companyId', value); emit('apply'); }"
        />
      </UFormField>

      <UFormField
        label="Buscar"
        name="search"
      >
        <UInput
          :model-value="search"
          placeholder="Descripción…"
          icon="i-lucide-search"
          color="neutral"
          variant="outline"
          size="md"
          class="w-full"
          @update:model-value="(value: string) => emit('update:search', value)"
          @keyup.enter="emit('apply')"
        />
      </UFormField>
    </div>
  </section>
</template>