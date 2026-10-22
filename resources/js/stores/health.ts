import { defineStore } from 'pinia';

import { getJson } from '@/lib/api';
import type { HealthResponse } from '@/types/api';

export type HealthStatus = 'idle' | 'loading' | 'ok' | 'error';

interface HealthState {
    status: HealthStatus;
    data: HealthResponse | null;
    error: string | null;
}

export const useHealthStore = defineStore('health', {
    state: (): HealthState => ({
        status: 'idle',
        data: null,
        error: null,
    }),

    actions: {
        async check(): Promise<void> {
            this.status = 'loading';
            this.error = null;

            try {
                this.data = await getJson<HealthResponse>('/api/v1/health');
                this.status = 'ok';
            } catch (error: unknown) {
                this.status = 'error';
                this.error = error instanceof Error ? error.message : 'No se pudo contactar la API.';
            }
        },
    },
});
