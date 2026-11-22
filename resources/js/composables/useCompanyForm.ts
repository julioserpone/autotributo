import { ref } from 'vue';

import { useToast } from '@nuxt/ui/composables/useToast';

import { ApiError } from '@/lib/api';
import type { Company, CompanyPayload } from '@/stores/company';
import { useCompanyStore } from '@/stores/company';

/** Estado editable del formulario de contribuyentes (todo texto, sin nulos). */
export interface CompanyFormState {
    ruc: string;
    legal_name: string;
    trade_name: string;
    regime: string;
    is_accounting_required: boolean;
    is_withholding_agent: boolean;
    withholding_resolution: string;
    economic_activity_code: string;
    address: string;
    email: string;
    phone: string;
    fiscal_year_start_month: number;
}

export type CompanyFieldErrors = Record<string, string[]>;

export const regimeOptions = [
    { label: 'Régimen general', value: 'general' },
    { label: 'RIMPE emprendedor', value: 'rimpe_emprendedor' },
    { label: 'RIMPE negocio popular', value: 'rimpe_negocio_popular' },
    { label: 'Persona natural', value: 'persona_natural' },
    { label: 'Sociedad', value: 'sociedad' },
];

export const monthOptions = Array.from({ length: 12 }, (_, index) => ({
    label: `Mes ${index + 1}`,
    value: index + 1,
}));

export function emptyCompanyForm(): CompanyFormState {
    return {
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

export function companyToForm(company: Company): CompanyFormState {
    return {
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
}

export function formToPayload(form: CompanyFormState): CompanyPayload {
    return {
        ruc: form.ruc.trim(),
        legal_name: form.legal_name.trim(),
        trade_name: form.trade_name.trim() || null,
        regime: form.regime,
        is_accounting_required: form.is_accounting_required,
        is_withholding_agent: form.is_withholding_agent,
        withholding_resolution: form.is_withholding_agent
            ? form.withholding_resolution.trim() || null
            : null,
        economic_activity_code: form.economic_activity_code.trim() || null,
        address: form.address.trim() || null,
        email: form.email.trim() || null,
        phone: form.phone.trim() || null,
        fiscal_year_start_month: form.fiscal_year_start_month,
    };
}

const RUC_PATTERN = /^\d{13}$/;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const ACTIVITY_CODE_PATTERN = /^[A-Za-z0-9]{1,10}$/;

/**
 * Validación de tipos del formulario. Devuelve un mapa `campo => mensajes`;
 * el backend vuelve a validar y sus errores sobrescriben este mapa.
 */
export function validateCompanyForm(form: CompanyFormState): CompanyFieldErrors {
    const errors: CompanyFieldErrors = {};
    const ruc = form.ruc.trim();
    const legalName = form.legal_name.trim();

    if (ruc.length !== 13) {
        errors.ruc = ['El RUC debe tener 13 caracteres.'];
    } else if (!RUC_PATTERN.test(ruc)) {
        errors.ruc = ['El RUC debe contener solo dígitos.'];
    }

    if (legalName === '') {
        errors.legal_name = ['La razón social es obligatoria.'];
    } else if (legalName.length > 300) {
        errors.legal_name = ['La razón social no puede superar 300 caracteres.'];
    }

    if (form.email.trim() !== '' && !EMAIL_PATTERN.test(form.email.trim())) {
        errors.email = ['El correo electrónico no es válido.'];
    }

    if (form.economic_activity_code.trim() !== '' && !ACTIVITY_CODE_PATTERN.test(form.economic_activity_code.trim())) {
        errors.economic_activity_code = ['El código debe ser alfanumérico de hasta 10 caracteres.'];
    }

    if (form.is_withholding_agent && form.withholding_resolution.trim() === '') {
        errors.withholding_resolution = ['La resolución es obligatoria para agentes de retención.'];
    }

    return errors;
}

/**
 * Estado y operaciones del formulario de contribuyentes.
 *
 * Mantiene el componente de modal enfocado en la presentación: la validación,
 * el payload y la llamada al store viven aquí.
 */
export function useCompanyForm() {
    const companyStore = useCompanyStore();
    const toast = useToast();

    const form = ref<CompanyFormState>(emptyCompanyForm());
    const fieldErrors = ref<CompanyFieldErrors>({});
    const generalError = ref<string | null>(null);
    const submitting = ref(false);

    function resetForm(company: Company | null): void {
        form.value = company ? companyToForm(company) : emptyCompanyForm();
        fieldErrors.value = {};
        generalError.value = null;
    }

    function clearErrors(): void {
        fieldErrors.value = {};
        generalError.value = null;
    }

    /**
     * Guarda el contribuyente (alta o edición).
     *
     * @returns `true` si el backend aceptó el cambio y se emitió el toast.
     */
    async function submit(company: Company | null): Promise<boolean> {
        clearErrors();

        const errors = validateCompanyForm(form.value);

        if (Object.keys(errors).length > 0) {
            fieldErrors.value = errors;
            return false;
        }

        submitting.value = true;

        try {
            if (company !== null) {
                await companyStore.updateCompany(company.id, formToPayload(form.value));
                toast.add({ title: 'Contribuyente actualizado', color: 'success' });
            } else {
                await companyStore.createCompany(formToPayload(form.value));
                toast.add({ title: 'Contribuyente registrado', color: 'success' });
            }

            return true;
        } catch (caught: unknown) {
            if (caught instanceof ApiError) {
                for (const [field, messages] of Object.entries(caught.errors)) {
                    fieldErrors.value[field] = messages;
                }

                generalError.value = caught.message;
            } else {
                generalError.value = caught instanceof Error ? caught.message : 'No se pudo guardar el contribuyente.';
            }

            return false;
        } finally {
            submitting.value = false;
        }
    }

    return {
        form,
        fieldErrors,
        generalError,
        submitting,
        resetForm,
        submit,
    };
}
