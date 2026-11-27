<script setup lang="ts">
import { computed } from 'vue';

import type { FiscalSignoffStage } from '@/stores/fiscalSignoffs';
import { fiscalSignoffGuidance } from '@/lib/fiscalSignoffGuidance';

const props = defineProps<{
    open: boolean;
    stage: FiscalSignoffStage | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    'open-source': [stage: FiscalSignoffStage];
}>();

const openModel = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});

const stageGuidance = computed(() => (
    props.stage === null ? null : fiscalSignoffGuidance[props.stage.key] ?? null
));

const hasSourceFiles = computed(() => (props.stage?.source_files?.length ?? 0) > 0);
</script>

<template>
  <UModal
    v-model:open="openModel"
    :title="stage ? `Guía: ${stage.title}` : 'Guía para la revisión contable'"
    :description="stage ? 'Qué revisar, cómo funciona hoy y qué falta para aprobar con confianza.' : 'Pasos sencillos para revisar las validaciones fiscales de Autotributo.'"
    :ui="{ content: 'sm:max-w-3xl' }"
  >
    <template #body>
      <div class="max-h-[70vh] space-y-5 overflow-y-auto pr-1 text-sm">
        <template v-if="stage && stageGuidance">
          <p class="text-muted">
            {{ stageGuidance.purpose }}
          </p>

          <section>
            <h3 class="font-semibold text-highlighted">
              Qué debes comprobar
            </h3>
            <ol class="mt-2 list-decimal space-y-2 pl-5 text-muted">
              <li
                v-for="item in stageGuidance.checklist"
                :key="item"
              >
                {{ item }}
              </li>
            </ol>
          </section>

          <section>
            <h3 class="font-semibold text-highlighted">
              Cómo funciona actualmente
            </h3>
            <p class="mt-1 text-muted">
              {{ stageGuidance.currentOperation }}
            </p>
          </section>

          <section>
            <h3 class="font-semibold text-highlighted">
              Qué resultado esperamos
            </h3>
            <p class="mt-1 text-muted">
              {{ stageGuidance.expectedOutcome }}
            </p>
          </section>

          <section class="rounded-lg border border-warning/40 bg-warning/5 p-4">
            <h3 class="font-semibold text-highlighted">
              Evidencia que debes revisar
            </h3>
            <p class="mt-1 text-muted">
              {{ stageGuidance.evidenceGap }}
            </p>
          </section>

          <section>
            <h3 class="font-semibold text-highlighted">
              Material de consulta
            </h3>
            <ul class="mt-1 list-inside list-disc space-y-1 text-muted">
              <li
                v-for="source in stageGuidance.sources"
                :key="source"
              >
                {{ source }}
              </li>
            </ul>
          </section>

          <section
            v-if="hasSourceFiles"
            class="rounded-lg border border-default bg-elevated/50 p-4"
          >
            <h3 class="font-semibold text-highlighted">
              Código fuente disponible
            </h3>
            <p class="mt-1 text-muted">
              Puedes abrir los archivos permitidos para esta etapa desde el visor controlado.
            </p>
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-code-2"
              label="Ver código fuente"
              class="mt-3"
              @click="emit('open-source', stage!)"
            />
          </section>
        </template>

        <template v-else-if="!stage">
          <section>
            <h3 class="font-semibold text-highlighted">
              Dónde empezar
            </h3>
            <ol class="mt-2 list-decimal space-y-2 pl-5 text-muted">
              <li>En el menú lateral, abre <strong>Validación contable</strong>. Ahí están las etapas pendientes, aprobadas y que requieren cambios.</li>
              <li>En cada etapa, pulsa <strong>Qué debo revisar</strong> para leer la lista de comprobación, el resultado esperado y las limitaciones actuales.</li>
              <li>Contrasta la información con la guía y las tablas oficiales del SRI. Si el detalle o los documentos necesarios todavía no están disponibles, no registres un OK.</li>
              <li>Si encuentras un error o falta información, escribe qué dato debe corregirse y pulsa <strong>Solicitar cambios</strong>. La observación es obligatoria.</li>
              <li>Si la evidencia respalda la regla, puedes escribir un comentario opcional y pulsar <strong>Dar OK</strong>.</li>
            </ol>
          </section>

          <section>
            <h3 class="font-semibold text-highlighted">
              Qué ocurre después
            </h3>
            <p class="mt-1 text-muted">
              La aplicación registra quién tomó la decisión y cuándo. La decisión más reciente aparece en la etapa y las anteriores permanecen en el historial. Si el contenido de una etapa cambia, esa revisión vuelve a quedar pendiente.
            </p>
          </section>

          <section class="rounded-lg border border-warning/40 bg-warning/5 p-4">
            <h3 class="font-semibold text-highlighted">
              Importante antes de aprobar
            </h3>
            <p class="mt-1 text-muted">
              Esta pantalla explica qué validar. Los archivos de código relevantes se pueden abrir desde el visor controlado; no necesitas rutas locales del repositorio. La guía de cada etapa indica lo que aún falta. Dar OK es una aprobación interna y no es una firma electrónica legal.
            </p>
          </section>

          <section>
            <h3 class="font-semibold text-highlighted">
              Consulta el borrador y los hallazgos
            </h3>
            <p class="mt-1 text-muted">
              En <strong>Formulario 104</strong> puedes revisar el borrador del período; un campo “No disponible” significa que falta información y no equivale a cero. En <strong>Hallazgos</strong> puedes consultar comprobantes con alertas.
            </p>
            <p class="mt-2 text-muted">
              Dar OK es una aprobación interna para dejar constancia de la revisión. No es una firma electrónica legal ni presenta la declaración al SRI.
            </p>
          </section>
        </template>

        <p
          v-else
          class="rounded-lg border border-warning/40 bg-warning/5 p-4 text-muted"
          role="alert"
        >
          Todavía no hay una guía preparada para esta etapa. No registres una aprobación hasta que se agreguen instrucciones y evidencia suficientes.
        </p>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full flex-wrap justify-end gap-2">
        <template v-if="!stage">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            label="Abrir Formulario 104"
            :to="{ name: 'form-104' }"
          />
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            label="Abrir Hallazgos"
            :to="{ name: 'fiscal-findings' }"
          />
        </template>
        <UButton
          color="neutral"
          variant="solid"
          size="sm"
          label="Cerrar guía"
          @click="openModel = false"
        />
      </div>
    </template>
  </UModal>
</template>
