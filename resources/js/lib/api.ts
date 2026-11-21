/**
 * Cliente HTTP de la SPA.
 *
 * La API responde con `application/problem+json` (RFC 9457, F1-09), por lo que
 * el error expone `title`, `detail` y, en validaciones, `errors` por campo.
 * Los endpoints que no devuelven cuerpo (`204`) se resuelven con `undefined`
 * en lugar de intentar parsear JSON.
 */

export interface ProblemDetails {
    type?: string;
    title?: string;
    status?: number;
    detail?: string;
    errors?: Record<string, string[]>;
    [key: string]: unknown;
}

export class ApiError extends Error {
    public constructor(
        public readonly status: number,
        message: string,
        public readonly problem: ProblemDetails | null = null,
    ) {
        super(message);
        this.name = 'ApiError';
    }

    /** Errores de validación por campo (`422`). Vacío si no es una validación. */
    public get errors(): Record<string, string[]> {
        return this.problem?.errors ?? {};
    }

    /** Primer mensaje de un campo concreto, para pintarlo junto al input. */
    public fieldError(field: string): string | null {
        return this.errors[field]?.[0] ?? null;
    }
}

type HttpMethod = 'GET' | 'POST' | 'PATCH' | 'DELETE';

interface RequestOptions {
    method?: HttpMethod;
    body?: unknown;
    headers?: Record<string, string> | undefined;
}

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function toApiError(response: Response): Promise<ApiError> {
    let problem: ProblemDetails | null = null;

    try {
        const data = (await response.json()) as ProblemDetails & { message?: string };

        if (data !== null && typeof data === 'object') {
            problem = data;
        }
    } catch {
        // El cuerpo no es JSON; se conserva el mensaje genérico.
    }

    const message =
        problem?.detail ??
        (typeof problem?.['message'] === 'string' ? problem['message'] : undefined) ??
        problem?.title ??
        `La API respondió con estado ${response.status}.`;

    return new ApiError(response.status, message, problem);
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
    const { method = 'GET', body, headers } = options;
    const sendsBody = body !== undefined;

    const response = await fetch(path, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(method === 'GET'
                ? {}
                : {
                      'X-CSRF-TOKEN': csrfToken(),
                      ...(sendsBody ? { 'Content-Type': 'application/json' } : {}),
                  }),
            ...headers,
        },
        ...(sendsBody ? { body: JSON.stringify(body) } : {}),
    });

    if (!response.ok) {
        throw await toApiError(response);
    }

    // `DELETE` y otras acciones sin cuerpo: no hay JSON que parsear.
    if (response.status === 204 || response.status === 205) {
        return undefined as T;
    }

    const text = await response.text();

    if (text.trim() === '') {
        return undefined as T;
    }

    return JSON.parse(text) as T;
}

export function getJson<T>(path: string): Promise<T> {
    return request<T>(path);
}

export function postJson<T>(path: string, body: unknown, headers?: Record<string, string>): Promise<T> {
    return request<T>(path, { method: 'POST', body, headers });
}

export function patchJson<T>(path: string, body: unknown, headers?: Record<string, string>): Promise<T> {
    return request<T>(path, { method: 'PATCH', body, headers });
}

export function deleteJson<T = void>(path: string, headers?: Record<string, string>): Promise<T> {
    return request<T>(path, { method: 'DELETE', headers });
}
