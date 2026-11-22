import { ref } from 'vue';

import { defineStore } from 'pinia';

import { ApiError, deleteJson, getJson, putJson, postJson } from '@/lib/api';

export interface SriCredential {
    id: string;
    company_id: string;
    username: string;
    has_password: boolean;
    status: string;
    failure_count: number;
    last_used_at: string | null;
    last_success_at: string | null;
    last_failure_at: string | null;
    rotated_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface SriCredentialPayload {
    username: string;
    password: string;
    password_confirmation: string;
}

interface SriCredentialResponse {
    data: SriCredential;
}

/**
 * Gestiona la credencial del portal SRI de una empresa.
 *
 * La contraseña nunca se devuelve ni se persiste en el cliente: el backend
 * solo expone `has_password`. Un `404` al consultar se trata como "sin
 * credencial", no como error, para que la UI pueda mostrar el estado vacío.
 */
export const useSriCredentialStore = defineStore('sriCredential', () => {
    const credential = ref<SriCredential | null>(null);
    const loading = ref(false);
    const loaded = ref(false);
    const saving = ref(false);
    const error = ref<string | null>(null);

    async function fetchCredential(companyId: string): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            const response = await getJson<{ data: SriCredential }>(`/api/v1/companies/${companyId}/credential`);
            credential.value = response.data;
        } catch (caught: unknown) {
            if (caught instanceof ApiError && caught.status === 404) {
                credential.value = null;
            } else {
                credential.value = null;
                error.value = caught instanceof Error ? caught.message : 'No se pudo cargar la credencial.';
            }
        } finally {
            loading.value = false;
            loaded.value = true;
        }
    }

    async function storeCredential(companyId: string, payload: SriCredentialPayload): Promise<SriCredential> {
        saving.value = true;
        error.value = null;

        try {
            const response = await putJson<SriCredentialResponse>(`/api/v1/companies/${companyId}/credential`, payload);
            credential.value = response.data;
            loaded.value = true;

            return response.data;
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudo guardar la credencial.';
            throw caught;
        } finally {
            saving.value = false;
        }
    }

    async function rotateCredential(companyId: string, password: string, confirmation: string): Promise<SriCredential> {
        saving.value = true;
        error.value = null;

        try {
            const response = await postJson<SriCredentialResponse>(`/api/v1/companies/${companyId}/credential/rotate`, {
                password,
                password_confirmation: confirmation,
            });
            credential.value = response.data;
            loaded.value = true;

            return response.data;
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudo rotar la credencial.';
            throw caught;
        } finally {
            saving.value = false;
        }
    }

    async function deleteCredential(companyId: string): Promise<void> {
        saving.value = true;
        error.value = null;

        try {
            await deleteJson(`/api/v1/companies/${companyId}/credential`);
            credential.value = null;
            loaded.value = true;
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudo eliminar la credencial.';
            throw caught;
        } finally {
            saving.value = false;
        }
    }

    function reset(): void {
        credential.value = null;
        loading.value = false;
        loaded.value = false;
        saving.value = false;
        error.value = null;
    }

    return {
        credential,
        loading,
        loaded,
        saving,
        error,
        fetchCredential,
        storeCredential,
        rotateCredential,
        deleteCredential,
        reset,
    };
});