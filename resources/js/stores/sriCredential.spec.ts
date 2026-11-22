import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { getJson, putJson, postJson, deleteJson } = vi.hoisted(() => ({
    getJson: vi.fn(),
    putJson: vi.fn(),
    postJson: vi.fn(),
    deleteJson: vi.fn(),
}));

vi.mock('@/lib/api', () => ({
    getJson,
    putJson,
    postJson,
    deleteJson,
    ApiError: class ApiError extends Error {
        status: number;
        constructor(status: number, message: string) {
            super(message);
            this.status = status;
        }
    },
}));

import { ApiError } from '@/lib/api';
import { useSriCredentialStore } from '@/stores/sriCredential';

const credential = {
    id: 'cred1',
    company_id: 'c1',
    username: '1790016919001',
    has_password: true,
    status: 'active',
    failure_count: 0,
    last_used_at: null,
    last_success_at: null,
    last_failure_at: null,
    rotated_at: null,
    created_at: null,
    updated_at: null,
};

describe('useSriCredentialStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('carga la credencial existente', async () => {
        getJson.mockResolvedValueOnce({ data: credential });

        const store = useSriCredentialStore();
        await store.fetchCredential('c1');

        expect(store.credential).toEqual(credential);
        expect(store.loaded).toBe(true);
        expect(store.error).toBeNull();
    });

    it('trata 404 como sin credencial', async () => {
        getJson.mockRejectedValueOnce(new ApiError(404, 'Not found'));

        const store = useSriCredentialStore();
        await store.fetchCredential('c1');

        expect(store.credential).toBeNull();
        expect(store.loaded).toBe(true);
        expect(store.error).toBeNull();
    });

    it('guarda una credencial nueva', async () => {
        putJson.mockResolvedValueOnce({ data: credential });

        const store = useSriCredentialStore();
        const result = await store.storeCredential('c1', {
            username: '1790016919001',
            password: 'secret123',
            password_confirmation: 'secret123',
        });

        expect(result).toEqual(credential);
        expect(store.credential).toEqual(credential);
        expect(store.saving).toBe(false);
    });

    it('rota la contraseña', async () => {
        const rotated = { ...credential, rotated_at: '2026-11-22T00:00:00Z' };
        postJson.mockResolvedValueOnce({ data: rotated });

        const store = useSriCredentialStore();
        const result = await store.rotateCredential('c1', 'newsecret', 'newsecret');

        expect(result).toEqual(rotated);
        expect(store.credential).toEqual(rotated);
    });

    it('elimina la credencial', async () => {
        getJson.mockResolvedValueOnce({ data: credential });
        const store = useSriCredentialStore();
        await store.fetchCredential('c1');

        deleteJson.mockResolvedValueOnce(undefined);
        await store.deleteCredential('c1');

        expect(store.credential).toBeNull();
        expect(deleteJson).toHaveBeenCalledWith('/api/v1/companies/c1/credential');
    });

    it('propaga el error al guardar', async () => {
        putJson.mockRejectedValueOnce(new ApiError(422, 'Validation error'));

        const store = useSriCredentialStore();

        await expect(store.storeCredential('c1', {
            username: '1790016919001',
            password: 'short',
            password_confirmation: 'short',
        })).rejects.toBeInstanceOf(ApiError);

        expect(store.error).toBe('Validation error');
        expect(store.saving).toBe(false);
    });
});