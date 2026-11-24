<script setup lang="ts">
import { computed } from 'vue';

import { findingCodeLabels, severityLabels, statusLabels } from '@/lib/fiscalFindings';
import { useCompanyStore } from '@/stores/company';

const companyStore = useCompanyStore();

defineProps<{
    companyId: string;
    status: string;
    severity: string;
    code: string;
    search: string;
}>();

const emit = defineEmits<{
    'update:companyId': [value: string];
    'update:status': [value: string];
    'update:severity': [value: string];
    'update:code': [value: string];
    'update:search': [value: string];
    apply: [];
}>();

const companyOptions = computed(() => [
    { label: 'Todas las empresas', value: 'all' },
    ...companyStore.companies.map((company) => ({
        label: company.trade_name || company.legal_name,
        value: company.id,
    })),
]);

const statusOptions = [
    ...Object.entries(statusLabels).map(([value, label]) => ({ label, value })),
    { label: 'Todos', value: 'all' },
];

const severityOptions = [
    { label: 'Todas las severidades', value: 'all' },
    ...Object.entries(severityLabels).map(([value, label]) => ({ label, value })),
];

const codeOptions = [
    { label: 'Todos los hallazgos', value: 'all' },
    ...Object.entries(findingCodeLabels).map(([value, label]) => ({ label, value })),
];
</script>

<template>
  <section class="rounded-lg border border-default bg-elevated/50 p-4">
    <div class="mb-3 flex items-center justify-between">
      <span class="text-xs text-muted">Filtra los hallazgos detectados por el motor de riesgos.</span>
    </div>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
      <UFormField
        label="Empresa"
        name="company"
      >
        <USelect
          :model-value="companyId"
          :items="companyOptions"
          color="neutral"
          variant="outline"
          size="md"
          class="w-full"
          @update:model-value="(value: string) => { emit('update:companyId', value); emit('apply'); }"
        />
      </UFormField>

      <UFormField
        label="Estado"
        name="status"
      >
        <USelect
          :model-value="status"
          :items="statusOptions"
          color="neutral"
          variant="outline"
          size="md"
          class="w-full"
          @update:model-value="(value: string) => { emit('update:status', value); emit('apply'); }"
        />
      </UFormField>

      <UFormField
        label="Severidad"
        name="severity"
      >
        <USelect
          :model-value="severity"
          :items="severityOptions"
          color="neutral"
          variant="outline"
          size="md"
          class="w-full"
          @update:model-value="(value: string) => { emit('update:severity', value); emit('apply'); }"
        />
      </UFormField>

      <UFormField
        label="Hallazgo"
        name="code"
      >
        <USelect
          :model-value="code"
          :items="codeOptions"
          color="neutral"
          variant="outline"
          size="md"
          class="w-full"
          @update:model-value="(value: string) => { emit('update:code', value); emit('apply'); }"
        />
      </UFormField>

      <UFormField
        label="Buscar"
        name="search"
      >
        <UInput
          :model-value="search"
          placeholder="Título o descripción…"
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
