/**
 * Textos de dominio SRI compartidos por las vistas.
 *
 * Los códigos (`document_types`, `error_code`) son identificadores estables del
 * backend y nunca deben mostrarse al usuario: aquí se traducen a etiquetas en
 * español. Si llega un código desconocido se devuelve un texto genérico, para
 * que la interfaz no termine mostrando convenciones `snake_case`.
 */

/** Códigos de tipo de comprobante del SRI que el robot descarga. */
export const documentTypeLabels: Record<string, string> = {
    '01': 'Facturas',
    '03': 'Liquidaciones de compra',
    '04': 'Notas de crédito',
    '05': 'Notas de débito',
    '06': 'Guías de remisión',
    '07': 'Comprobantes de retención',
};

/** Tipos ofrecidos en el formulario de nueva sincronización. */
export const documentTypeOptions: Array<{ label: string; value: string }> = ['01', '03', '04', '05', '07']
    .map((code) => ({ label: documentTypeLabels[code] as string, value: code }));

/**
 * Títulos legibles para `sri_sync_runs.error_code`.
 *
 * Las claves son los códigos que escriben las Actions del dominio (`FinalizeSriRun`,
 * `ParseSriRun`, `ResolveStaleSriRun`, `ProcessSriRunJob`, `RecoverSriRunFromS3` y
 * `SriRobotCallbackController`). El detalle técnico sigue viviendo en
 * `error_message`, que sí puede mostrarse al usuario.
 */
export const syncErrorLabels: Record<string, string> = {
    partial_import: 'Importación parcial',
    import_failed: 'No se pudo importar',
    import_timeout: 'Importación tardó demasiado',
    robot_timeout: 'El robot no respondió',
    robot_failed: 'Falló la descarga del robot',
    no_documents: 'Sin comprobantes en el período',
    no_access_keys: 'Archivos del robot inválidos',
    incomplete_package: 'Paquete del robot incompleto',
};

/** Traduce un código de error del run; nunca devuelve el código crudo. */
export function syncErrorLabel(code: string | null | undefined): string {
    if (!code) {
        return 'Error de sincronización';
    }

    return syncErrorLabels[code] ?? 'Error de sincronización';
}

/** Estados de archivo (`sri_sync_run_files.status`). */
export const fileStatusLabels: Record<string, string> = {
    pending: 'Pendiente',
    available: 'Disponible',
    missing: 'No encontrado',
    invalid: 'Inválido',
    processed: 'Procesado',
};

/**
 * Resultado de importar un archivo (`sri_sync_run_files.import_outcome`).
 *
 * `null` cubre los archivos que aún no se procesan o que fallaron antes de
 * intentar la importación: en ese caso no hay resultado que mostrar.
 */
export const importOutcomeLabels: Record<string, string> = {
    created: 'Importado',
    updated: 'Actualizado',
    skipped: 'Omitido (ya registrado)',
};

/**
 * Errores de archivo del `Sri` (`sri_sync_run_files.error_code`).
 *
 * A diferencia de los errores del run, aquí el detalle técnico no se muestra:
 * `error_message` ya viene en español y esta etiqueta solo resume el origen.
 */
export const fileErrorLabels: Record<string, string> = {
    sri_unavailable: 'El SRI no devolvió el comprobante',
    invalid_xml: 'XML inválido',
    unexpected_error: 'Error inesperado',
};

/** Traduce el estado de un archivo; cae a un texto genérico si es desconocido. */
export function fileStatusLabel(status: string | null | undefined): string {
    if (!status) {
        return 'Sin estado';
    }

    return fileStatusLabels[status] ?? 'Estado desconocido';
}

/** Traduce el resultado de importación; `null` cuando aún no lo hay. */
export function importOutcomeLabel(outcome: string | null | undefined): string | null {
    if (!outcome) {
        return null;
    }

    return importOutcomeLabels[outcome] ?? 'Resultado desconocido';
}

/** Traduce el código de error de un archivo; `null` si no hay error. */
export function fileErrorLabel(code: string | null | undefined): string | null {
    if (!code) {
        return null;
    }

    return fileErrorLabels[code] ?? 'Error al procesar el archivo';
}

/** Lista de tipos de documento como texto (`01` → «Facturas»). */
export function formatDocumentTypes(codes: string[] | null | undefined): string {
    const types = codes ?? [];

    if (types.length === 0) {
        return 'Todos los tipos';
    }

    return types.map((code) => documentTypeLabels[code] ?? code).join(', ');
}

/** Períodos consultados o, si el run no los tiene, el rango de fechas. */
export function formatPeriods(run: {
    periods?: string[] | null;
    date_from?: string | null;
    date_to?: string | null;
}): string {
    if (run.periods && run.periods.length > 0) {
        return run.periods.join(', ');
    }

    return `${run.date_from ?? '—'} → ${run.date_to ?? '—'}`;
}
