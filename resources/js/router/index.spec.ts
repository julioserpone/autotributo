import { setActivePinia, createPinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import router from '@/router';

const authState = vi.hoisted(() => ({
    user: null as { id: number; name: string; email: string; memberships: { tenant_id: number; role: string }[] } | null,
    loading: false,
    fetchMe: vi.fn(async () => {}),
}));

vi.mock('@/stores/auth', () => ({
    useAuthStore: () => ({
        get user() {
            return authState.user;
        },
        get loading() {
            return authState.loading;
        },
        fetchMe: authState.fetchMe,
    }),
}));

const companyState = vi.hoisted(() => ({
    loaded: false,
    hasCompany: false,
    requiresTwoFactorSetup: false,
    fetchCompanies: vi.fn(async () => {}),
}));

vi.mock('@/stores/company', () => ({
    useCompanyStore: () => ({
        get loaded() {
            return companyState.loaded;
        },
        get hasCompany() {
            return companyState.hasCompany;
        },
        get requiresTwoFactorSetup() {
            return companyState.requiresTwoFactorSetup;
        },
        fetchCompanies: companyState.fetchCompanies,
    }),
}));

const loggedUser = {
    id: 1,
    name: 'User',
    email: 'u@e.com',
    memberships: [{ tenant_id: 1, role: 'owner' }],
};

describe('router guards', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        authState.user = null;
        authState.loading = false;
        authState.fetchMe.mockReset();
        authState.fetchMe.mockImplementation(async () => {});
        companyState.loaded = false;
        companyState.hasCompany = false;
        companyState.requiresTwoFactorSetup = false;
        companyState.fetchCompanies.mockReset();
        companyState.fetchCompanies.mockImplementation(async () => {});
    });

    it('redirige a login si no hay usuario autenticado', async () => {
        authState.user = null;
        await router.push('/sync');
        expect(router.currentRoute.value.name).toBe('login');
    });

    it('redirige a two-factor-setup si el 2FA está pendiente', async () => {
        authState.user = loggedUser;
        companyState.loaded = true;
        companyState.requiresTwoFactorSetup = true;

        await router.push('/sync');
        expect(router.currentRoute.value.name).toBe('two-factor-setup');
    });

    it('redirige a companies?onboarding=1 si no hay contribuyentes', async () => {
        authState.user = loggedUser;
        companyState.loaded = true;
        companyState.hasCompany = false;

        await router.push('/sync');
        expect(router.currentRoute.value.name).toBe('companies');
        expect(router.currentRoute.value.query.onboarding).toBe('1');
    });

    it('permite la navegación si hay contribuyente activo', async () => {
        authState.user = loggedUser;
        companyState.loaded = true;
        companyState.hasCompany = true;

        await router.push('/sync');
        expect(router.currentRoute.value.name).toBe('sync');
    });

    it('no dispara fetchCompanies en rutas sin requiresCompany', async () => {
        authState.user = loggedUser;

        await router.push('/companies');
        expect(companyState.fetchCompanies).not.toHaveBeenCalled();
        expect(router.currentRoute.value.name).toBe('companies');
    });

    it('dispara fetchCompanies una vez en rutas requiresCompany si no está cargado', async () => {
        authState.user = loggedUser;
        companyState.loaded = false;
        companyState.hasCompany = true;

        await router.push('/fiscal_documents');
        expect(companyState.fetchCompanies).toHaveBeenCalled();
        expect(router.currentRoute.value.name).toBe('fiscal_documents');
    });
});