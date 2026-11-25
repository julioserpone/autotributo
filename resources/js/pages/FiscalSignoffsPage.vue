<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';

import { useToast } from '@nuxt/ui/composables/useToast';
import AppPage from '@/components/layout/AppPage.vue';
import FiscalSignoffGuideModal from '@/components/fiscal/FiscalSignoffGuideModal.vue';
import AppShell from '@/layouts/AppShell.vue';
import type { FiscalSignoffDecision, FiscalSignoffStage } from '@/stores/fiscalSignoffs';
import { useAuthStore } from '@/stores/auth';
import { useFiscalSignoffStore } from '@/stores/fiscalSignoffs';

const authStore = useAuthStore();
const store = useFiscalSignoffStore();
const toast = useToast();
const comments = reactive<Record<string, string>>({});
const guideOpen = ref(false);
const guideStage = ref<FiscalSignoffStage | null>(null);

const canReview = computed(() => {
    const role = authStore.user?.memberships?.[0]?.role;

    return role === 'owner' || role === 'accountant';
});

const approvedCount = computed(() => store.stages.filter((stage) => stage.status === 'approved').length);
const pendingCount = computed(() => store.stages.filter((stage) => stage.status === 'pending').length);
const changesCount = computed(() => store.stages.filter((stage) => stage.status === 'changes_requested').length);

function statusLabel(status: FiscalSignoffStage['status']): string {
    return {
        pending: 'Pendiente',
        approved: 'Aprobado',
        changes_requested: 'Requiere cambios',
    }[status];
}

function updateComment(stageKey: string, value: string | string[]): void {
    comments[stageKey] = Array.isArray(value) ? value.join('\n') : value;
}

function openGuide(stage: FiscalSignoffStage | null = null): void {
    guideStage.value = stage;
    guideOpen.value = true;
}

async function submitReview(stage: FiscalSignoffStage, decision: FiscalSignoffDecision): Promise<void> {
    try {
        await store.review(stage, decision, comments[stage.key] ?? '');
        comments[stage.key] = '';
        toast.add({
            title: decision === 'approved' ? 'Validación registrada' : 'Observaciones registradas',
            color: 'success',
        });
    } catch (caught: unknown) {
        toast.add({
            title: 'No se pudo registrar la validación',
            description: caught instanceof Error ? caught.message : 'Intenta de nuevo.',
            color: 'error',
        });
    }
}

onMounted(() => {
    void store.fetchStages();
});
</script>

<template>
  <AppShell>
    <AppPage
      title="Validación contable"
      description="Revisa las etapas fiscales con una guía en lenguaje sencillo. Las decisiones quedan registradas con tu usuario y la fecha."
    >
      <UCard class="mb-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="font-semibold">
              ¿Primera vez en esta pantalla?
            </h2>
            <p class="mt-1 text-sm text-muted">
              La guía explica el recorrido, cómo dejar una observación y qué información falta revisar en cada etapa.
            </p>
          </div>
          <UButton
            color="primary"
            variant="soft"
            icon="i-lucide-book-open-check"
            label="Abrir guía paso a paso"
            class="self-start sm:self-auto"
            @click="openGuide()"
          />
        </div>
      </UCard>

      <div class="grid gap-3 sm:grid-cols-3">
        <UCard>
          <p class="text-sm text-muted">
            Pendientes
          </p>
          <p class="mt-1 text-2xl font-semibold">
            {{ pendingCount }}
          </p>
        </UCard>
        <UCard>
          <p class="text-sm text-muted">
            Aprobadas
          </p>
          <p class="mt-1 text-2xl font-semibold">
            {{ approvedCount }}
          </p>
        </UCard>
        <UCard>
          <p class="text-sm text-muted">
            Requieren cambios
          </p>
          <p class="mt-1 text-2xl font-semibold">
            {{ changesCount }}
          </p>
        </UCard>
      </div>

      <div
        v-if="store.loading && store.stages.length === 0"
        class="py-12 text-center text-sm text-muted"
        role="status"
        aria-live="polite"
      >
        Cargando etapas de validación…
      </div>

      <div
        v-else-if="store.error"
        class="flex flex-col items-center gap-3 py-12"
        role="alert"
      >
        <p class="text-sm text-error">
          {{ store.error }}
        </p>
        <UButton
          color="neutral"
          variant="outline"
          label="Reintentar"
          @click="store.fetchStages"
        />
      </div>

      <div
        v-else-if="store.stages.length === 0"
        class="py-12 text-center text-sm text-muted"
      >
        No hay etapas de validación registradas.
      </div>

      <div
        v-else
        class="space-y-4"
      >
        <UCard
          v-for="stage in store.stages"
          :key="stage.key"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 class="font-semibold">
                {{ stage.title }}
              </h2>
              <p class="mt-1 text-sm text-muted">
                {{ stage.summary }}
              </p>
            </div>
            <UBadge
              :color="stage.status === 'approved' ? 'success' : stage.status === 'changes_requested' ? 'warning' : 'neutral'"
              variant="subtle"
            >
              {{ statusLabel(stage.status) }} · v{{ stage.version }}
            </UBadge>
          </div>

          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            icon="i-lucide-circle-help"
            label="Qué debo revisar"
            class="mt-3"
            @click="openGuide(stage)"
          />

          <div
            v-if="stage.latest_review"
            class="mt-4 rounded-lg border border-default bg-elevated/50 p-3 text-sm"
          >
            <p>
              Última decisión: <strong>{{ statusLabel(stage.latest_review.decision) }}</strong>
              por {{ stage.latest_review.reviewed_by?.name ?? 'usuario' }}
              · {{ new Date(stage.latest_review.reviewed_at).toLocaleString('es-EC') }}
            </p>
            <p
              v-if="stage.latest_review.comments"
              class="mt-1 whitespace-pre-wrap text-muted"
            >
              {{ stage.latest_review.comments }}
            </p>
          </div>

          <details
            v-if="stage.history.length > 1"
            class="mt-3 text-sm"
          >
            <summary class="cursor-pointer text-muted">
              Ver historial ({{ stage.history.length }})
            </summary>
            <ol class="mt-2 space-y-2 border-l border-default pl-4">
              <li
                v-for="(review, index) in stage.history"
                :key="`${review.stage_fingerprint}-${review.reviewed_at}-${index}`"
              >
                <p>
                  v{{ review.stage_version }} · {{ statusLabel(review.decision) }}
                  por {{ review.reviewed_by?.name ?? 'usuario' }}
                  · {{ new Date(review.reviewed_at).toLocaleString('es-EC') }}
                  <span
                    v-if="!review.current_version"
                    class="text-dimmed"
                  >(versión anterior)</span>
                </p>
                <p
                  v-if="review.comments"
                  class="whitespace-pre-wrap text-muted"
                >
                  {{ review.comments }}
                </p>
              </li>
            </ol>
          </details>

          <div
            v-if="canReview"
            class="mt-4 space-y-3 border-t border-default pt-4"
          >
            <div class="w-full min-w-0 overflow-x-auto">
              <UTextarea
                :model-value="comments[stage.key] ?? ''"
                :label="stage.status === 'approved' ? 'Comentario opcional' : 'Observaciones (obligatorias para solicitar cambios)'"
                :rows="4"
                :maxlength="5000"
                class="w-full"
                :ui="{ base: 'w-full min-w-full resize' }"
                @update:model-value="updateComment(stage.key, $event)"
              />
            </div>
            <p class="text-xs text-muted">
              Puedes arrastrar la esquina inferior derecha para ampliar el campo hacia abajo o hacia la derecha.
            </p>
            <div class="flex flex-wrap gap-2">
              <UButton
                color="success"
                icon="i-lucide-check"
                label="Dar OK"
                :loading="store.savingStage === stage.key"
                @click="submitReview(stage, 'approved')"
              />
              <UButton
                color="warning"
                variant="outline"
                icon="i-lucide-message-square-warning"
                label="Solicitar cambios"
                :disabled="!(comments[stage.key] ?? '').trim()"
                :loading="store.savingStage === stage.key"
                @click="submitReview(stage, 'changes_requested')"
              />
            </div>
          </div>
          <p
            v-else
            class="mt-4 border-t border-default pt-4 text-sm text-muted"
          >
            Tu rol puede consultar las validaciones, pero no registrar decisiones.
          </p>
        </UCard>
      </div>

      <p class="text-xs text-dimmed">
        Esta aprobación es un registro interno auditable; no constituye una firma electrónica con validez legal ni reemplaza la declaración presentada al SRI.
      </p>

      <FiscalSignoffGuideModal
        v-model:open="guideOpen"
        :stage="guideStage"
      />
    </AppPage>
  </AppShell>
</template>
