<script setup lang="ts">
import { computed, ref } from 'vue';

import AppShell from '@/layouts/AppShell.vue';
import AppPage from '@/components/layout/AppPage.vue';
import { useAuthStore } from '@/stores/auth';
import { postJson } from '@/lib/api';

const authStore = useAuthStore();

const email = ref('');
const role = ref('assistant');
const loading = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const roleOptions = [
    { label: 'Owner', value: 'owner' },
    { label: 'Contador', value: 'accountant' },
    { label: 'Asistente', value: 'assistant' },
    { label: 'Viewer', value: 'viewer' },
];

const canInvite = computed(() => {
    const role = authStore.user?.memberships?.[0]?.role ?? '';
    return role === 'owner' || role === 'accountant';
});

async function submit(): Promise<void> {
    if (!email.value.trim()) {
        error.value = 'Indica el correo del invitado.';
        return;
    }

    loading.value = true;
    error.value = null;
    success.value = null;

    try {
        const response = await postJson<{ data: { email: string; resent: boolean } }>('/api/v1/auth/invitations', {
            email: email.value.trim(),
            role: role.value,
        });

        const wasResent = response.data?.resent === true;

        success.value = wasResent
            ? `Se reenvió la invitación a ${email.value.trim()} (la anterior fue anulada).`
            : `Se envió la invitación a ${email.value.trim()}.`;

        email.value = '';
    } catch (caught: unknown) {
        error.value = caught instanceof Error ? caught.message : 'No se pudo enviar la invitación.';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
  <AppShell>
    <AppPage
      title="Equipo e invitaciones"
      description="Invita a colegas al estudio con un rol específico."
    >
      <div
        v-if="!canInvite"
        class="rounded-md border border-warning/40 bg-warning/10 px-4 py-3 text-sm text-warning"
      >
        Solo los owners y contadores pueden invitar usuarios.
      </div>

      <section
        v-else
        class="max-w-xl space-y-4 rounded-lg border border-default bg-elevated/50 p-5"
      >
        <UFormField
          label="Correo electrónico"
          name="email"
        >
          <UInput
            v-model="email"
            type="email"
            color="neutral"
            variant="outline"
            class="w-full"
            placeholder="colega@estudio.ec"
          />
        </UFormField>

        <UFormField
          label="Rol"
          name="role"
        >
          <USelect
            v-model="role"
            :items="roleOptions"
            value-key="value"
            color="neutral"
            variant="outline"
            class="w-full"
          />
        </UFormField>

        <UAlert
          v-if="error"
          color="error"
          variant="soft"
          icon="i-lucide-alert-circle"
          :description="error"
        />

        <UAlert
          v-if="success"
          color="success"
          variant="soft"
          icon="i-lucide-check-circle"
          :description="success"
        />

        <UButton
          color="primary"
          size="md"
          icon="i-lucide-send"
          label="Enviar invitación"
          :loading="loading"
          :disabled="loading"
          @click="submit"
        />
      </section>
    </AppPage>
  </AppShell>
</template>