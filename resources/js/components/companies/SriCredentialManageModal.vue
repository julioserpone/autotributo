<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import type { Company } from '@/stores/company';

import { useSriCredentialStore } from '@/stores/sriCredential';
import SriCredentialFormModal from '@/components/companies/SriCredentialFormModal.vue';
import SriCredentialRotateModal from '@/components/companies/SriCredentialRotateModal.vue';

const props = defineProps<{
    open: boolean;
    company: Company | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const store = useSriCredentialStore();

const formOpen = ref(false);
const rotateOpen = ref(false);
const confirmDelete = ref(false);
const deleting = ref(false);

const companyLabel = computed(() => {
    if (!props.company) {
        return '';
    }

    return `${props.company.legal_name} · RUC ${props.company.ruc}`;
});

watch(() => props.open, (value) => {
    if (value && props.company) {
        store.reset();
        void store.fetchCredential(props.company.id);
        confirmDelete.value = false;
    }
});

async function remove(): Promise<void> {
    if (!props.company) {
        return;
    }

    deleting.value = true;

    try {
        await store.deleteCredential(props.company.id);
        confirmDelete.value = false;
    } finally {
        deleting.value = false;
    }
}

function statusLabel(status: string): string {
    switch (status) {
        case 'active':
            return 'Activa';
        case 'blocked':
            return 'Bloqueada';
        case 'disabled':
            return 'Deshabilitada';
        default:
            return status;
    }
}

function statusColor(status: string): 'success' | 'warning' | 'error' | 'neutral' {
    switch (status) {
        case 'active':
            return 'success';
        case 'blocked':
            return 'error';
        default:
            return 'neutral';
    }
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    try {
        return new Intl.DateTimeFormat('es-EC', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
    } catch {
        return value;
    }
}
</script>

<template>
  <UModal
    :open="open"
    title="Credencial del portal SRI"
    :description="companyLabel || 'Gestiona el acceso del robot al portal del SRI para este contribuyente.'"
    @update:open="emit('update:open', $event)"
  >
    <template #body>
      <div
        v-if="store.loading"
        class="flex items-center justify-center py-8 text-muted"
      >
        <UIcon
          name="i-lucide-loader-2"
          class="size-5 animate-spin"
        />
      </div>

      <div
        v-else-if="store.error"
        class="rounded-md border border-error/40 bg-error/10 px-4 py-3 text-sm text-error"
      >
        {{ store.error }}
      </div>

      <div
        v-else-if="!store.credential"
        class="flex flex-col items-center gap-3 py-6 text-center"
      >
        <UIcon
          name="i-lucide-key-round"
          class="size-8 text-dimmed"
        />
        <p class="text-sm text-muted">
          Este contribuyente aún no tiene credencial del portal SRI configurada.
        </p>
        <UButton
          color="primary"
          size="sm"
          icon="i-lucide-plus"
          label="Configurar credencial"
          @click="formOpen = true"
        />
      </div>

      <div
        v-else
        class="space-y-4"
      >
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div class="rounded-md border border-default bg-elevated/50 p-3">
            <p class="text-2xs uppercase tracking-wider text-dimmed">
              Usuario
            </p>
            <p class="mt-1 font-mono text-sm text-highlighted">
              {{ store.credential.username }}
            </p>
          </div>
          <div class="rounded-md border border-default bg-elevated/50 p-3">
            <p class="text-2xs uppercase tracking-wider text-dimmed">
              Estado
            </p>
            <UBadge
              :color="statusColor(store.credential.status)"
              variant="soft"
              size="sm"
              class="mt-1"
            >
              {{ statusLabel(store.credential.status) }}
            </UBadge>
          </div>
          <div class="rounded-md border border-default bg-elevated/50 p-3">
            <p class="text-2xs uppercase tracking-wider text-dimmed">
              Intentos fallidos
            </p>
            <p class="mt-1 font-mono text-sm text-highlighted">
              {{ store.credential.failure_count }}
            </p>
          </div>
          <div class="rounded-md border border-default bg-elevated/50 p-3">
            <p class="text-2xs uppercase tracking-wider text-dimmed">
              Último uso
            </p>
            <p class="mt-1 text-sm text-highlighted">
              {{ formatDate(store.credential.last_used_at) }}
            </p>
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            label="Rotar contraseña"
            @click="rotateOpen = true"
          />
          <UButton
            color="error"
            variant="soft"
            size="sm"
            icon="i-lucide-trash-2"
            label="Eliminar"
            @click="confirmDelete = true"
          />
        </div>

        <UAlert
          v-if="confirmDelete"
          color="error"
          variant="soft"
          icon="i-lucide-alert-triangle"
          title="Eliminar credencial"
          description="El robot dejará de poder descargar comprobantes de este contribuyente hasta que se configure una nueva."
        >
          <template #actions>
            <UButton
              color="error"
              size="xs"
              label="Eliminar"
              :loading="deleting"
              @click="remove"
            />
            <UButton
              color="neutral"
              variant="outline"
              size="xs"
              label="Cancelar"
              @click="confirmDelete = false"
            />
          </template>
        </UAlert>
      </div>
    </template>

    <template #footer>
      <UButton
        color="neutral"
        variant="outline"
        size="sm"
        label="Cerrar"
        @click="emit('update:open', false)"
      />
    </template>
  </UModal>

  <SriCredentialFormModal
    v-model:open="formOpen"
    :company="props.company"
    @saved="() => { if (props.company) void store.fetchCredential(props.company.id); }"
  />

  <SriCredentialRotateModal
    v-model:open="rotateOpen"
    :company="props.company"
    @rotated="() => { if (props.company) void store.fetchCredential(props.company.id); }"
  />
</template>