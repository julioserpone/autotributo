<script setup lang="ts">
import { computed, ref, watchEffect } from 'vue';

import { cn } from '@/lib/cn';

import type { TableColumn, TableRow } from './types';

type RowKey = string | number;

const props = withDefaults(
    defineProps<{
        columns: TableColumn[];
        rows: TableRow[];
        rowKey?: string | ((row: TableRow) => RowKey);
        caption?: string;
        loading?: boolean;
        error?: string | null;
        emptyText?: string;
        skeletonRows?: number;
        selectable?: boolean;
        selected?: RowKey[];
    }>(),
    {
        rowKey: 'id',
        caption: 'Tabla de datos',
        loading: false,
        error: null,
        emptyText: 'Todavía no hay datos.',
        skeletonRows: 5,
        selectable: false,
        selected: () => [],
    },
);

const emit = defineEmits<{
    'update:selected': [value: RowKey[]];
}>();

const selectAll = ref<HTMLInputElement | null>(null);

const columnCount = computed(() => props.columns.length + (props.selectable ? 1 : 0));
const selectedSet = computed(() => new Set(props.selected));
const allSelected = computed(
    () => props.rows.length > 0 && props.rows.every((row, index) => selectedSet.value.has(rowKeyOf(row, index))),
);

watchEffect(
    () => {
        if (selectAll.value !== null) {
            selectAll.value.indeterminate = props.selected.length > 0 && !allSelected.value;
        }
    },
    { flush: 'post' },
);

function rowKeyOf(row: TableRow, index: number): RowKey {
    if (typeof props.rowKey === 'function') {
        return props.rowKey(row);
    }

    const value = row[props.rowKey];

    return typeof value === 'string' || typeof value === 'number' ? value : index;
}

function isSelected(row: TableRow, index: number): boolean {
    return selectedSet.value.has(rowKeyOf(row, index));
}

function toggleRow(row: TableRow, index: number): void {
    const key = rowKeyOf(row, index);
    const next = selectedSet.value.has(key)
        ? props.selected.filter((current) => current !== key)
        : [...props.selected, key];

    emit('update:selected', next);
}

function toggleAll(): void {
    if (allSelected.value) {
        emit('update:selected', []);

        return;
    }

    emit('update:selected', props.rows.map((row, index) => rowKeyOf(row, index)));
}
</script>

<template>
  <div
    class="overflow-hidden rounded-card border border-line bg-surface"
    :aria-busy="loading || undefined"
  >
    <div class="overflow-x-auto">
      <table class="w-full border-collapse text-left text-sm">
        <caption class="sr-only">
          {{ caption }}
        </caption>

        <thead class="bg-raised/60">
          <tr>
            <th
              v-if="selectable"
              class="w-10 px-3 py-3"
              scope="col"
            >
              <input
                ref="selectAll"
                type="checkbox"
                class="size-4 rounded border-line-strong bg-canvas accent-accent"
                :checked="allSelected"
                aria-label="Seleccionar todas las filas"
                @change="toggleAll"
              >
            </th>

            <th
              v-for="column in columns"
              :key="column.key"
              scope="col"
              :style="column.width ? { width: column.width } : undefined"
              :class="
                cn(
                  'px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted',
                  column.align === 'right' && 'text-right',
                  column.align === 'center' && 'text-center',
                  column.class,
                )
              "
            >
              {{ column.label }}
            </th>
          </tr>
        </thead>

        <tbody>
          <template v-if="loading">
            <tr
              v-for="index in skeletonRows"
              :key="`skeleton-${index}`"
              class="border-t border-line"
            >
              <td
                v-if="selectable"
                class="px-3 py-3"
              >
                <div class="size-4 animate-pulse rounded bg-raised" />
              </td>
              <td
                v-for="column in columns"
                :key="column.key"
                class="px-4 py-3"
              >
                <div
                  class="h-4 animate-pulse rounded bg-raised"
                  :class="column.align === 'right' && 'ml-auto'"
                  :style="{ width: `${40 + ((index * 13 + column.key.length * 7) % 40)}%` }"
                />
              </td>
            </tr>
          </template>

          <tr v-else-if="error">
            <td
              :colspan="columnCount"
              class="px-4 py-10 text-center text-sm text-danger-ink"
              role="alert"
            >
              {{ error }}
            </td>
          </tr>

          <tr v-else-if="rows.length === 0">
            <td
              :colspan="columnCount"
              class="px-4 py-10 text-center"
            >
              <slot name="empty">
                <p class="text-sm text-faint">
                  {{ emptyText }}
                </p>
              </slot>
            </td>
          </tr>

          <template v-else>
            <tr
              v-for="(row, index) in rows"
              :key="rowKeyOf(row, index)"
              class="border-t border-line transition hover:bg-raised/40"
            >
              <td
                v-if="selectable"
                class="px-3 py-3"
              >
                <input
                  type="checkbox"
                  class="size-4 rounded border-line-strong bg-canvas accent-accent"
                  :checked="isSelected(row, index)"
                  :aria-label="`Seleccionar fila ${index + 1}`"
                  @change="toggleRow(row, index)"
                >
              </td>

              <td
                v-for="column in columns"
                :key="column.key"
                :class="
                  cn(
                    'px-4 py-3 text-ink',
                    column.align === 'right' && 'text-right tabular-nums',
                    column.align === 'center' && 'text-center',
                    column.class,
                  )
                "
              >
                <slot
                  :name="`cell-${column.key}`"
                  :row="row"
                  :value="row[column.key]"
                  :index="index"
                >
                  {{ row[column.key] ?? '—' }}
                </slot>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>
</template>
