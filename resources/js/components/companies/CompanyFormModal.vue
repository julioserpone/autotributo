<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import type { Company } from '@/stores/company';
import { useCompanyForm, monthOptions, regimeOptions } from '@/composables/useCompanyForm';
import EconomicActivityField from '@/components/companies/EconomicActivityField.vue';

const props = defineProps<{
    open: boolean;
    company: Company | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    saved: [];
}>();

const isEdit = computed(() => props.company !== null);

const openModel = ref(props.open);

const {
    form,
    fieldErrors,
    generalError,
    submitting,
    resetForm,
    submit: submitForm,
} = useCompanyForm();

watch(() => props.open, (value) => {
    openModel.value = value;

    if (value) {
        resetForm(props.company);
    }
});

watch(openModel, (value) => emit('update:open', value));

watch(() => props.company, (value) => {
    if (openModel.value) {
        resetForm(value);
    }
});

async function submit(): Promise<void> {
    const saved = await submitForm(props.company);

    if (saved) {
        emit('saved');
        openModel.value = false;
    }
}
</script>

<template>
  <UModal
    v-model:open="openModel"
    :title="isEdit ? 'Editar contribuyente' : 'Registrar contribuyente'"
    description="Datos tributarios del contribuyente ante el SRI."
  >
    <template #body>
      <form
        class="space-y-4"
        @submit.prevent="submit"
      >
        <div
          v-if="generalError"
          role="alert"
          class="rounded-md border border-error/40 bg-error/10 px-3 py-2 text-xs text-error"
        >
          {{ generalError }}
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <UFormField
            label="RUC"
            name="ruc"
            :error="fieldErrors.ruc?.[0] ?? false"
            required
          >
            <UInput
              v-model="form.ruc"
              color="neutral"
              variant="soft"
              size="md"
              maxlength="13"
              inputmode="numeric"
              placeholder="1790016919001"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Régimen tributario"
            name="regime"
            :error="fieldErrors.regime?.[0] ?? false"
          >
            <USelect
              v-model="form.regime"
              :items="regimeOptions"
              color="neutral"
              variant="soft"
              size="md"
              class="w-full"
            />
          </UFormField>
        </div>

        <UFormField
          label="Razón social"
          name="legal_name"
          :error="fieldErrors.legal_name?.[0] ?? false"
          required
        >
          <UInput
            v-model="form.legal_name"
            color="neutral"
            variant="soft"
            size="md"
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Nombre comercial"
          name="trade_name"
          :error="fieldErrors.trade_name?.[0] ?? false"
        >
          <UInput
            v-model="form.trade_name"
            color="neutral"
            variant="soft"
            size="md"
            class="w-full"
          />
        </UFormField>

        <div class="grid gap-4 sm:grid-cols-2">
          <EconomicActivityField
            v-model="form.economic_activity_code"
            :error="fieldErrors.economic_activity_code?.[0] ?? false"
            :open="openModel"
          />

          <UFormField
            label="Mes de inicio del ejercicio"
            name="fiscal_year_start_month"
            :error="fieldErrors.fiscal_year_start_month?.[0] ?? false"
          >
            <USelect
              v-model="form.fiscal_year_start_month"
              :items="monthOptions"
              color="neutral"
              variant="soft"
              size="md"
              class="w-full"
            />
          </UFormField>
        </div>

        <UFormField
          label="Dirección"
          name="address"
          :error="fieldErrors.address?.[0] ?? false"
        >
          <UInput
            v-model="form.address"
            color="neutral"
            variant="soft"
            size="md"
            class="w-full"
          />
        </UFormField>

        <div class="grid gap-4 sm:grid-cols-2">
          <UFormField
            label="Correo electrónico"
            name="email"
            :error="fieldErrors.email?.[0] ?? false"
          >
            <UInput
              v-model="form.email"
              type="email"
              color="neutral"
              variant="soft"
              size="md"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Teléfono"
            name="phone"
            :error="fieldErrors.phone?.[0] ?? false"
          >
            <UInput
              v-model="form.phone"
              color="neutral"
              variant="soft"
              size="md"
              class="w-full"
            />
          </UFormField>
        </div>

        <div class="space-y-2 rounded-md border border-default bg-elevated/40 p-3">
          <UCheckbox
            v-model="form.is_accounting_required"
            label="Obligado a llevar contabilidad"
          />
          <UCheckbox
            v-model="form.is_withholding_agent"
            label="Agente de retención"
          />
          <UFormField
            v-if="form.is_withholding_agent"
            label="Resolución de agente de retención"
            name="withholding_resolution"
            :error="fieldErrors.withholding_resolution?.[0] ?? false"
            required
          >
            <UInput
              v-model="form.withholding_resolution"
              color="neutral"
              variant="soft"
              size="md"
              class="w-full"
            />
          </UFormField>
        </div>

        <div class="flex items-center justify-end gap-2 pt-2">
          <UButton
            color="neutral"
            variant="ghost"
            :disabled="submitting"
            @click="openModel = false"
          >
            Cancelar
          </UButton>
          <UButton
            type="submit"
            color="primary"
            :loading="submitting"
            :disabled="submitting"
          >
            {{ isEdit ? 'Guardar cambios' : 'Registrar' }}
          </UButton>
        </div>
      </form>
    </template>
  </UModal>
</template>
