import { ref } from 'vue';

import { defineStore } from 'pinia';

import { getJson, postJson } from '@/lib/api';

export type FiscalSignoffDecision = 'approved' | 'changes_requested';
export type FiscalSignoffStatus = FiscalSignoffDecision | 'pending';

export interface FiscalSignoffReview {
    stage_key: string;
    stage_version: number;
    stage_fingerprint: string;
    decision: FiscalSignoffDecision;
    comments: string | null;
    reviewed_by: { name: string } | null;
    reviewed_at: string;
    current_version?: boolean;
}

export interface FiscalSignoffStage {
    key: string;
    version: number;
    title: string;
    summary: string;
    references: string[];
    fingerprint: string;
    status: FiscalSignoffStatus;
    latest_review: FiscalSignoffReview | null;
    history: FiscalSignoffReview[];
}

export const useFiscalSignoffStore = defineStore('fiscal_signoffs', () => {
    const stages = ref<FiscalSignoffStage[]>([]);
    const loading = ref(false);
    const savingStage = ref<string | null>(null);
    const error = ref<string | null>(null);

    async function fetchStages(): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            const response = await getJson<{ data: FiscalSignoffStage[] }>('/api/v1/fiscal-signoffs');
            stages.value = response.data ?? [];
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudieron cargar las validaciones.';
        } finally {
            loading.value = false;
        }
    }

    async function review(
        stage: FiscalSignoffStage,
        decision: FiscalSignoffDecision,
        comments: string,
    ): Promise<void> {
        savingStage.value = stage.key;

        try {
            await postJson<{ data: FiscalSignoffReview }>(
                `/api/v1/fiscal-signoffs/${stage.key}/reviews`,
                {
                    decision,
                    stage_version: stage.version,
                    stage_fingerprint: stage.fingerprint,
                    ...(comments.trim() !== '' ? { comments: comments.trim() } : {}),
                },
            );
            await fetchStages();
        } finally {
            savingStage.value = null;
        }
    }

    return {
        stages,
        loading,
        savingStage,
        error,
        fetchStages,
        review,
    };
});
