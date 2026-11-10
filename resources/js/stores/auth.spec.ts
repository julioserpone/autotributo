import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { getJson, postJson } = vi.hoisted(() => ({
    getJson: vi.fn(),
    postJson: vi.fn(),
}));

vi.mock('@/lib/api', () => ({ getJson, postJson }));

import { useAuthStore } from '@/stores/auth';

const userPayload = {
    id: 1,
    name: 'Owner',
    email: 'owner@autotributo.test',
    memberships: [{ tenant_id: 1, role: 'owner' }],
};

describe('useAuthStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('marca la configuración de 2FA cuando el owner no la tiene activa', async () => {
        postJson.mockResolvedValueOnce({ data: { two_factor_setup_required: true } });
        getJson.mockResolvedValueOnce({ data: userPayload });

        const store = useAuthStore();
        const result = await store.login({ email: 'owner@autotributo.test', password: 'password' });

        expect(result).toEqual({ two_factor_required: false, two_factor_setup_required: true });
        expect(getJson).toHaveBeenCalledWith('/api/v1/auth/me');
        expect(store.user?.email).toBe('owner@autotributo.test');
    });

    it('solicita el desafío cuando el usuario ya tiene 2FA', async () => {
        postJson.mockResolvedValueOnce({ data: { two_factor_required: true } });

        const store = useAuthStore();
        const result = await store.login({ email: 'owner@autotributo.test', password: 'password' });

        expect(result).toEqual({ two_factor_required: true, two_factor_setup_required: false });
        expect(getJson).not.toHaveBeenCalled();
        expect(store.user).toBeNull();
    });

    it('carga el usuario cuando el login es directo', async () => {
        postJson.mockResolvedValueOnce({ data: userPayload });
        getJson.mockResolvedValueOnce({ data: userPayload });

        const store = useAuthStore();
        const result = await store.login({ email: 'owner@autotributo.test', password: 'password' });

        expect(result).toEqual({ two_factor_required: false, two_factor_setup_required: false });
        expect(store.user?.email).toBe('owner@autotributo.test');
        expect(store.isAuthenticated).toBe(true);
    });
});
