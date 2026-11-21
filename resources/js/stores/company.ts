import { computed, ref } from 'vue';

import { defineStore } from 'pinia';

import { ApiError, deleteJson, getJson, patchJson, postJson } from '@/lib/api';

const STORAGE_KEY = 'autotributo.company';

export interface Company {
    id: string;
    ruc: string;
    legal_name: string;
    trade_name: string | null;
    regime: string;
    regime_label: string;
    is_accounting_required: boolean;
    is_withholding_agent: boolean;
    withholding_resolution: string | null;
    economic_activity_code: string | null;
    address: string | null;
    email: string | null;
    phone: string | null;
    fiscal_year_start_month: number;
    status: string;
    settings: Record<string, unknown> | null;
    created_at: string | null;
    updated_at: string | null;
}

/**
 * Cuerpo aceptado por `POST/PATCH /api/v1/companies`.
 *
 * Los campos opcionales solo se envían si el formulario los conoce; el backend
 * valida RUC, régimen y coherencia de la resolución de agente de retención.
 */
export interface CompanyPayload {
    ruc: string;
    legal_name: string;
    trade_name?: string | null;
    regime?: string;
    is_accounting_required?: boolean;
    is_withholding_agent?: boolean;
    withholding_resolution?: string | null;
    economic_activity_code?: string | null;
    address?: string | null;
    email?: string | null;
    phone?: string | null;
    fiscal_year_start_month?: number;
}

interface CompanyResponse {
    data: Company;
}

function readActiveId(): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

function persistActiveId(id: string | null): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        if (id === null) {
            window.localStorage.removeItem(STORAGE_KEY);
        } else {
            window.localStorage.setItem(STORAGE_KEY, id);
        }
    } catch {
        // Storage no disponible: no bloquea el cambio en memoria.
    }
}

/** Mantiene el mismo orden que la API (`orderBy legal_name`). */
function sortByName(companies: Company[]): Company[] {
    return [...companies].sort((a, b) => a.legal_name.localeCompare(b.legal_name, 'es'));
}

export const useCompanyStore = defineStore('company', () => {
    const companies = ref<Company[]>([]);
    const activeId = ref<string | null>(readActiveId());
    const loading = ref(false);
    const loaded = ref(false);
    const error = ref<string | null>(null);
    const requiresTwoFactorSetup = ref(false);

    const activeCompany = computed(() => companies.value.find((company) => company.id === activeId.value) ?? null);
    const hasCompany = computed(() => companies.value.length > 0);

    function setActive(id: string | null): void {
        activeId.value = id;
        persistActiveId(id);
    }

    function upsert(company: Company): void {
        const others = companies.value.filter((item) => item.id !== company.id);
        companies.value = sortByName([...others, company]);
    }

    async function fetchCompanies(): Promise<void> {
        loading.value = true;
        error.value = null;
        requiresTwoFactorSetup.value = false;

        try {
            const response = await getJson<{ data: Company[] }>('/api/v1/companies?per_page=100');
            companies.value = sortByName(response.data);

            if (!companies.value.some((company) => company.id === activeId.value)) {
                setActive(companies.value[0]?.id ?? null);
            }
        } catch (caught: unknown) {
            companies.value = [];
            setActive(null);

            if (caught instanceof ApiError && caught.status === 403) {
                requiresTwoFactorSetup.value = true;
            }

            error.value = caught instanceof Error ? caught.message : 'No se pudieron cargar las empresas.';
        } finally {
            loading.value = false;
            loaded.value = true;
        }
    }

    async function fetchCompany(id: string): Promise<Company> {
        const response = await getJson<CompanyResponse>(`/api/v1/companies/${id}`);

        upsert(response.data);

        return response.data;
    }

    async function createCompany(payload: CompanyPayload): Promise<Company> {
        const response = await postJson<CompanyResponse>('/api/v1/companies', payload);

        upsert(response.data);
        setActive(response.data.id);
        loaded.value = true;

        return response.data;
    }

    async function updateCompany(id: string, payload: Partial<CompanyPayload>): Promise<Company> {
        const response = await patchJson<CompanyResponse>(`/api/v1/companies/${id}`, payload);

        upsert(response.data);

        return response.data;
    }

    async function archiveCompany(id: string): Promise<void> {
        await deleteJson(`/api/v1/companies/${id}`);

        companies.value = companies.value.filter((company) => company.id !== id);

        if (activeId.value === id) {
            setActive(companies.value[0]?.id ?? null);
        }
    }

    return {
        companies,
        activeId,
        loading,
        loaded,
        error,
        requiresTwoFactorSetup,
        activeCompany,
        hasCompany,
        setActive,
        fetchCompanies,
        fetchCompany,
        createCompany,
        updateCompany,
        archiveCompany,
    };
});
