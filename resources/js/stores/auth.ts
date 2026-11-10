import { computed, ref } from 'vue';

import { defineStore } from 'pinia';

import { getJson, postJson } from '@/lib/api';

export interface AuthMembership {
    tenant_id: number;
    role: string;
}

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    memberships: AuthMembership[];
}

interface LoginPayload {
    email: string;
    password: string;
    remember?: boolean;
}

export interface LoginResult {
    two_factor_required: boolean;
    two_factor_setup_required: boolean;
}

export const useAuthStore = defineStore('auth', () => {
    const user = ref<AuthUser | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);

    const isAuthenticated = computed(() => user.value !== null);

    async function fetchMe(): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            const response = await getJson<{ data: AuthUser }>('/api/v1/auth/me');
            user.value = response.data;
        } catch {
            user.value = null;
        } finally {
            loading.value = false;
        }
    }

    async function login(payload: LoginPayload): Promise<LoginResult> {
        loading.value = true;
        error.value = null;

        try {
            const response = await postJson<{
                data: {
                    two_factor_required?: boolean;
                    two_factor_setup_required?: boolean;
                    id?: number;
                };
            }>('/api/v1/auth/login', payload);

            if (response.data.two_factor_setup_required) {
                // La sesión ya está creada, pero limitada a configurar el 2FA.
                await fetchMe();

                return { two_factor_required: false, two_factor_setup_required: true };
            }

            if (response.data.two_factor_required) {
                return { two_factor_required: true, two_factor_setup_required: false };
            }

            await fetchMe();

            return { two_factor_required: false, two_factor_setup_required: false };
        } catch (caught: unknown) {
            user.value = null;
            error.value = caught instanceof Error ? caught.message : 'No se pudo iniciar sesión.';
            throw caught;
        } finally {
            loading.value = false;
        }
    }

    async function logout(): Promise<void> {
        loading.value = true;

        try {
            await postJson('/api/v1/auth/logout', {});
        } finally {
            user.value = null;
            loading.value = false;
        }
    }

    return {
        user,
        loading,
        error,
        isAuthenticated,
        fetchMe,
        login,
        logout,
    };
});
