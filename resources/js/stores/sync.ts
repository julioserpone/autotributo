import { computed, ref } from 'vue';

import { defineStore } from 'pinia';

import { getJson, postJson } from '@/lib/api';

export type SyncRunStatus =
    | 'queued'
    | 'invoking'
    | 'downloading'
    | 'parsing'
    | 'completed'
    | 'partial'
    | 'failed'
    | 'cancelled';

export interface SyncRunTotals {
    found: number;
    imported: number;
    skipped: number;
    failed: number;
}

export interface SyncRun {
    id: string;
    company_id: string | null;
    status: SyncRunStatus;
    is_active: boolean;
    poll_after_ms: number | null;
    date_from: string | null;
    date_to: string | null;
    periods: string[];
    document_types: string[] | null;
    totals: SyncRunTotals;
    progress: number | null;
    error_code: string | null;
    error_message: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
}

export interface SyncRunFile {
    id: number;
    file_name: string;
    s3_key: string;
    checksum: string | null;
    document_type: string | null;
    status: string;
    error_code: string | null;
    error_message: string | null;
    import_outcome: string | null;
    source: string | null;
    processed_at: string | null;
}

const activeStatuses: SyncRunStatus[] = ['queued', 'invoking', 'downloading', 'parsing'];

export const useSyncStore = defineStore('sync', () => {
    const runs = ref<SyncRun[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);

    const selectedRunId = ref<string | null>(null);
    const selectedRun = ref<SyncRun | null>(null);
    const selectedFiles = ref<SyncRunFile[]>([]);
    const polling = ref(false);

    let pollTimer: ReturnType<typeof setTimeout> | null = null;

    const activeRuns = computed(() => runs.value.filter((run) => activeStatuses.includes(run.status)));

    function statusLabel(status: SyncRunStatus): string {
        switch (status) {
            case 'queued': return 'En cola';
            case 'invoking': return 'Invocando robot';
            case 'downloading': return 'Descargando';
            case 'parsing': return 'Procesando';
            case 'completed': return 'Completado';
            case 'partial': return 'Parcial';
            case 'failed': return 'Fallido';
            case 'cancelled': return 'Cancelado';
            default: return status;
        }
    }

    function statusColor(status: SyncRunStatus): 'success' | 'warning' | 'error' | 'neutral' | 'info' {
        switch (status) {
            case 'completed': return 'success';
            case 'partial': return 'warning';
            case 'failed': return 'error';
            case 'cancelled': return 'neutral';
            default: return 'info';
        }
    }

    async function fetchRuns(): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            const response = await getJson<{ data: SyncRun[] }>('/api/v1/sync-runs?per_page=25');
            runs.value = response.data;
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudieron cargar las sincronizaciones.';
        } finally {
            loading.value = false;
        }
    }

    async function loadRun(id: string): Promise<void> {
        selectedRunId.value = id;

        try {
            const [runResponse, filesResponse] = await Promise.all([
                getJson<{ data: SyncRun }>(`/api/v1/sync-runs/${id}`),
                getJson<{ data: SyncRunFile[] }>(`/api/v1/sync-runs/${id}/files`),
            ]);

            selectedRun.value = runResponse.data;
            selectedFiles.value = filesResponse.data;

            startPollingIfNeeded();
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudo cargar el run.';
        }
    }

    function startPollingIfNeeded(): void {
        stopPolling();

        if (!selectedRun.value?.is_active) return;

        const interval = selectedRun.value.poll_after_ms ?? 5000;
        polling.value = true;

        pollTimer = setTimeout(async () => {
            if (!selectedRunId.value) return;

            try {
                const [runResponse, filesResponse] = await Promise.all([
                    getJson<{ data: SyncRun }>(`/api/v1/sync-runs/${selectedRunId.value}`),
                    getJson<{ data: SyncRunFile[] }>(`/api/v1/sync-runs/${selectedRunId.value}/files`),
                ]);

                selectedRun.value = runResponse.data;
                selectedFiles.value = filesResponse.data;

                const index = runs.value.findIndex((run) => run.id === selectedRunId.value);
                if (index !== -1 && selectedRun.value) {
                    runs.value[index] = selectedRun.value;
                }

                if (selectedRun.value.is_active) {
                    startPollingIfNeeded();
                } else {
                    polling.value = false;
                    void fetchRuns();
                }
            } catch {
                polling.value = false;
            }
        }, interval);
    }

    function stopPolling(): void {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        polling.value = false;
    }

    async function startSync(payload: {
        companyId: string;
        periods: string[];
        documentTypes: string[];
        force: boolean;
    }): Promise<string | null> {
        const idempotencyKey = (typeof crypto !== 'undefined' && 'randomUUID' in crypto)
            ? crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

        try {
            const response = await postJson<{
                data: Array<{ id: string; status: string }>;
                meta: { count: number };
                status_url: string;
            }>('/api/v1/sync-runs', {
                company_id: payload.companyId,
                periods: payload.periods,
                document_types: payload.documentTypes,
                force: payload.force,
            }, { 'Idempotency-Key': idempotencyKey });

            const firstId = response.data?.[0]?.id ?? null;

            await fetchRuns();

            if (firstId) {
                await loadRun(firstId);
            }

            return firstId;
        } catch (caught: unknown) {
            error.value = caught instanceof Error ? caught.message : 'No se pudo iniciar la sincronización.';
            return null;
        }
    }

    function monthsBetween(from: string, to: string): string[] {
        const result: string[] = [];
        const start = new Date(`${from}-01`);
        const end = new Date(`${to}-01`);

        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return result;

        const cursor = new Date(start);
        while (cursor <= end) {
            const year = cursor.getFullYear();
            const month = String(cursor.getMonth() + 1).padStart(2, '0');
            result.push(`${year}-${month}`);
            cursor.setMonth(cursor.getMonth() + 1);
        }

        return result;
    }

    return {
        runs,
        loading,
        error,
        selectedRunId,
        selectedRun,
        selectedFiles,
        polling,
        activeRuns,
        statusLabel,
        statusColor,
        fetchRuns,
        loadRun,
        stopPolling,
        startSync,
        monthsBetween,
    };
});
