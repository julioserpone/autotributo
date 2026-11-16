import { computed, ref } from 'vue';

import { defineStore } from 'pinia';

import { ApiError, getJson } from '@/lib/api';

const STORAGE_KEY = 'autotributo.company';

export interface Company {
    id: string;
    ruc: string;
    legal_name: string;
    trade_name: string | null;
    regime: string;
    regime_label: string;
    status: string;
    is_withholding_agent: boolean;
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

    async function fetchCompanies(): Promise<void> {
        loading.value = true;
        error.value = null;
        requiresTwoFactorSetup.value = false;

        try {
            const response = await getJson<{ data: Company[] }>('/api/v1/companies?per_page=100');
            companies.value = response.data;

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
    };
});
