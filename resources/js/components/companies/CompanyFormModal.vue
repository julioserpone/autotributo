<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import type { Company, CompanyPayload } from '@/stores/company';
import { ApiError } from '@/lib/api';
import { useCompanyStore } from '@/stores/company';
import { useToast } from '@nuxt/ui/composables/useToast';

const props = defineProps<{
    open: boolean;
    company: Company | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    saved: [];
}>();

const companyStore = useCompanyStore();
const toast = useToast();

const isEdit = computed(() => props.company !== null);

const openModel = ref(props.open);

const regimeOptions = [
    { label: 'Régimen general', value: 'general' },
    { label: 'RIMPE emprendedor', value: 'rimpe_emprendedor' },
    { label: 'RIMPE negocio popular', value: 'rimpe_negocio_popular' },
    { label: 'Persona natural', value: 'persona_natural' },
    { label: 'Sociedad', value: 'sociedad' },
];

const monthOptions = Array.from({ length: 12 }, (_, index) => ({
    label: `Mes ${index + 1}`,
    value: index + 1,
}));

const form = ref({
    ruc: '',
    legal_name: '',
    trade_name: '',
    regime: 'general',
    is_accounting_required: true,
    is_withholding_agent: false,
    withholding_resolution: '',
    economic_activity_code: '',
    address: '',
    email: '',
    phone: '',
    fiscal_year_start_month: 1,
});

const fieldErrors = ref<Record<string, string[]>>({});
const generalError = ref<string | null>(null);
const submitting = ref(false);

function resetForm(company: Company | null): void {
    if (company) {
        form.value = {
            ruc: company.ruc,
            legal_name: company.legal_name,
            trade_name: company.trade_name ?? '',
            regime: company.regime,
            is_accounting_required: company.is_accounting_required,
            is_withholding_agent: company.is_withholding_agent,
            withholding_resolution: company.withholding_resolution ?? '',
            economic_activity_code: company.economic_activity_code ?? '',
            address: company.address ?? '',
            email: company.email ?? '',
            phone: company.phone ?? '',
            fiscal_year_start_month: company.fiscal_year_start_month,
        };
    } else {
        form.value = {
            ruc: '',
            legal_name: '',
            trade_name: '',
            regime: 'general',
            is_accounting_required: true,
            is_withholding_agent: false,
            withholding_resolution: '',
            economic_activity_code: '',
            address: '',
            email: '',
            phone: '',
            fiscal_year_start_month: 1,
        };
    }

    fieldErrors.value = {};
    generalError.value = null;
}

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

function buildPayload(): CompanyPayload {
    const payload: CompanyPayload = {
        ruc: form.value.ruc.trim(),
        legal_name: form.value.legal_name.trim(),
        trade_name: form.value.trade_name.trim() || null,
        regime: form.value.regime,
        is_accounting_required: form.value.is_accounting_required,
        is_withholding_agent: form.value.is_withholding_agent,
        withholding_resolution: form.value.is_withholding_agent
            ? form.value.withholding_resolution.trim() || null
            : null,
        economic_activity_code: form.value.economic_activity_code.trim() || null,
        address: form.value.address.trim() || null,
        email: form.value.email.trim() || null,
        phone: form.value.phone.trim() || null,
        fiscal_year_start_month: form.value.fiscal_year_start_month,
    };

    return payload;
}

async function submit(): Promise<void> {
    fieldErrors.value = {};
    generalError.value = null;

    if (form.value.ruc.trim().length !== 13) {
        fieldErrors.value.ruc = ['El RUC debe tener 13 caracteres.'];
        return;
    }

    if (form.value.legal_name.trim() === '') {
        fieldErrors.value.legal_name = ['La razón social es obligatoria.'];
        return;
    }

    if (form.value.is_withholding_agent && form.value.withholding_resolution.trim() === '') {
        fieldErrors.value.withholding_resolution = ['La resolución es obligatoria para agentes de retención.'];
        return;
    }

    submitting.value = true;

    try {
        if (isEdit.value && props.company) {
            await companyStore.updateCompany(props.company.id, buildPayload());
            toast.add({ title: 'Contribuyente actualizado', color: 'success' });
        } else {
            await companyStore.createCompany(buildPayload());
            toast.add({ title: 'Contribuyente registrado', color: 'success' });
        }

        emit('saved');
        openModel.value = false;
    } catch (caught: unknown) {
        if (caught instanceof ApiError) {
            for (const [field, messages] of Object.entries(caught.errors)) {
                fieldErrors.value[field] = messages;
            }

            generalError.value = caught.message;
        } else {
            generalError.value = caught instanceof Error ? caught.message : 'No se pudo guardar el contribuyente.';
        }
    } finally {
        submitting.value = false;
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
          <UFormField
            label="Actividad económica (código)"
            name="economic_activity_code"
            :error="fieldErrors.economic_activity_code?.[0] ?? false"
          >
            <UInput
              v-model="form.economic_activity_code"
              color="neutral"
              variant="soft"
              size="md"
              maxlength="10"
              class="w-full"
            />
          </UFormField>

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
