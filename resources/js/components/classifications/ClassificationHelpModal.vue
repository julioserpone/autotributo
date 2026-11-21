<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const openModel = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});
</script>

<template>
  <UModal
    v-model:open="openModel"
    title="¿Cómo clasificar?"
    description="Guía rápida para usar la cola de revisión."
  >
    <template #body>
      <div class="space-y-3 text-sm text-muted">
        <p>Cada fila es un <strong>ítem de un comprobante</strong> (línea de factura) al que el sistema asignó una categoría de gasto, ya sea por una regla o por IA.</p>
        <p><strong>Confianza</strong>: porcentaje de certeza de la sugerencia. Verde (≥90%), ámbar (70–89%), rojo (&lt;70%).</p>
        <p><strong>Aceptar</strong>: confirma la categoría sugerida (o la que elijas). <strong>Rechazar</strong>: devuelve el ítem a pendiente para que una regla posterior lo reintente.</p>
        <p><strong>Crear regla</strong>: genera una regla reutilizable a partir del patrón de la descripción, para que comprobantes futuros se clasifiquen automáticamente.</p>
        <p><strong>Selección múltiple</strong>: marca varios ítems, elige una categoría común y acepta o rechaza en bloque. No se permite aceptar en bloque sin categoría seleccionada.</p>
        <p class="text-xs">
          Atajos: <kbd>j</kbd>/<kbd>k</kbd> navegar · <kbd>espacio</kbd> seleccionar · <kbd>a</kbd> aceptar · <kbd>r</kbd> rechazar · <kbd>Enter</kbd> crear regla.
        </p>
      </div>
    </template>
    <template #footer>
      <UButton
        color="neutral"
        variant="outline"
        size="sm"
        label="Cerrar"
        @click="openModel = false"
      />
    </template>
  </UModal>
</template>