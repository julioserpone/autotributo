<script setup lang="ts">
import { computed } from 'vue';

import { periodLabel } from '@/lib/dashboard';

const props = defineProps<{
    open: boolean;
    year: number;
    month: number;
    requiresTwoFactorSetup?: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const openModel = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});

const period = computed(() => periodLabel(props.year, props.month));
</script>

<template>
  <UModal
    v-model:open="openModel"
    title="Sincronizar con el SRI"
    :description="`Descarga los comprobantes de ${period} desde el portal del SRI.`"
  >
    <template #body>
      <div class="space-y-3">
        <p class="text-sm text-muted">
          La sincronización se lanza desde el monitor con la empresa activa. Al cerrar el
          proceso, los agregados del período se recalculan y este panel se actualiza.
        </p>

        <UAlert
          v-if="requiresTwoFactorSetup"
          color="warning"
          variant="soft"
          icon="i-lucide-shield-alert"
          title="Verificación en dos pasos requerida"
          description="Activa la verificación en dos pasos para acceder a los endpoints de negocio."
        >
          <template #actions>
            <UButton
              color="neutral"
              variant="outline"
              size="xs"
              label="Configurar"
              :to="{ name: 'two-factor-setup' }"
            />
          </template>
        </UAlert>
      </div>
    </template>

    <template #footer>
      <div class="flex items-center justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          size="sm"
          label="Cerrar"
          @click="openModel = false"
        />
        <UButton
          color="primary"
          variant="solid"
          size="sm"
          icon="i-lucide-radar"
          label="Ir al monitor"
          :to="{ name: 'sync' }"
        />
      </div>
    </template>
  </UModal>
</template>
