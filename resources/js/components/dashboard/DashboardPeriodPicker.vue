<script setup lang="ts">
import { computed } from 'vue';

import { monthLabel, periodLabel } from '@/lib/dashboard';

/**
 * Selector del período fiscal del panel (F4-08).
 *
 * Vive en la cabecera del contribuyente: el período es contexto de toda la
 * página (IVA, proveedores, alertas y topes), no solo del comparativo de IVA.
 */
const props = defineProps<{
    year: number;
    month: number;
    loading?: boolean;
}>();

const emit = defineEmits<{
    (e: 'change-period', payload: { year: number; month: number }): void;
}>();

const periodOptions = computed(() => {
    const options: { value: string; label: string }[] = [];

    for (let index = 0; index < 12; index += 1) {
        const date = new Date(props.year, props.month - 1 - index, 1);
        options.push({
            value: `${date.getFullYear()}-${date.getMonth() + 1}`,
            label: periodLabel(date.getFullYear(), date.getMonth() + 1),
        });
    }

    return options;
});

const currentLabel = computed(() => `${monthLabel(props.month)} ${props.year}`);

function shift(delta: number): void {
    const date = new Date(props.year, props.month - 1 + delta, 1);
    emit('change-period', { year: date.getFullYear(), month: date.getMonth() + 1 });
}

function selectPeriod(value: string): void {
    const [rawYear, rawMonth] = value.split('-').map(Number);

    if (rawYear === undefined || rawMonth === undefined) return;

    emit('change-period', { year: rawYear, month: rawMonth });
}
</script>

<template>
  <div class="flex items-center gap-1.5">
    <UButton
      color="neutral"
      variant="outline"
      size="xs"
      icon="i-lucide-chevron-left"
      :disabled="loading"
      aria-label="Mes anterior"
      @click="shift(-1)"
    />
    <label class="sr-only">Período fiscal</label>
    <select
      class="rounded-md border border-default bg-elevated px-2.5 py-1.5 text-xs font-medium text-highlighted focus:border-accented focus:outline-none"
      :value="`${year}-${month}`"
      :disabled="loading"
      :aria-label="`Período fiscal, ${currentLabel}`"
      @change="selectPeriod(($event.target as HTMLSelectElement).value)"
    >
      <option
        v-for="option in periodOptions"
        :key="option.value"
        :value="option.value"
      >
        {{ option.label }}
      </option>
    </select>
    <UButton
      color="neutral"
      variant="outline"
      size="xs"
      icon="i-lucide-chevron-right"
      :disabled="loading"
      aria-label="Mes siguiente"
      @click="shift(1)"
    />
  </div>
</template>
