import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ApiError, deleteJson, getJson, patchJson, postJson } from '@/lib/api';

const fetchMock = vi.fn();

function jsonResponse(body: unknown, status = 200): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
        text: async () => JSON.stringify(body),
    } as unknown as Response;
}

function emptyResponse(status = 204): Response {
    return {
        ok: true,
        status,
        json: async () => {
            throw new Error('respuesta sin cuerpo');
        },
        text: async () => '',
    } as unknown as Response;
}

function firstCall(): { path: string; init: RequestInit } {
    const call = fetchMock.mock.calls[0];

    if (call === undefined) {
        throw new Error('fetch no fue llamado');
    }

    return { path: call[0] as string, init: call[1] as RequestInit };
}

function headersOf(init: RequestInit): Record<string, string> {
    return init.headers as Record<string, string>;
}

describe('lib/api', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', fetchMock);
        fetchMock.mockReset();

        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = 'token-de-prueba';
        document.head.appendChild(meta);
    });

    afterEach(() => {
        document.head.querySelector('meta[name="csrf-token"]')?.remove();
        vi.unstubAllGlobals();
    });

    it('getJson devuelve el cuerpo y no envía CSRF', async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse({ data: [{ id: 'c1' }] }));

        const body = await getJson<{ data: { id: string }[] }>('/api/v1/companies');

        const { path, init } = firstCall();

        expect(body.data[0]?.id).toBe('c1');
        expect(path).toBe('/api/v1/companies');
        expect(init.method).toBe('GET');
        expect(headersOf(init)['X-CSRF-TOKEN']).toBeUndefined();
        expect(init.credentials).toBe('same-origin');
    });

    it('postJson envía JSON, CSRF y cabeceras adicionales', async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse({ data: { id: 'run-1' } }, 201));

        await postJson('/api/v1/sync-runs', { company_id: 'c1' }, { 'Idempotency-Key': 'abc' });

        const { init } = firstCall();
        const headers = headersOf(init);

        expect(init.method).toBe('POST');
        expect(init.body).toBe(JSON.stringify({ company_id: 'c1' }));
        expect(headers['Content-Type']).toBe('application/json');
        expect(headers['X-CSRF-TOKEN']).toBe('token-de-prueba');
        expect(headers['Idempotency-Key']).toBe('abc');
    });

    it('deleteJson resuelve sin cuerpo en un 204 y envía CSRF', async () => {
        fetchMock.mockResolvedValueOnce(emptyResponse(204));

        await expect(deleteJson('/api/v1/companies/c1')).resolves.toBeUndefined();

        const { init } = firstCall();
        const headers = headersOf(init);

        expect(init.method).toBe('DELETE');
        expect(init.body).toBeUndefined();
        expect(headers['X-CSRF-TOKEN']).toBe('token-de-prueba');
        expect(headers['Content-Type']).toBeUndefined();
    });

    it('tolera un 200 con cuerpo vacío', async () => {
        fetchMock.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({}),
            text: async () => '   ',
        } as unknown as Response);

        await expect(getJson('/api/v1/ping')).resolves.toBeUndefined();
    });

    it('expone los errores por campo de un 422 problem+json', async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(
                {
                    type: 'https://autotributo.com/problems/validation',
                    title: 'The given data was invalid.',
                    status: 422,
                    detail: 'El RUC no es válido.',
                    errors: {
                        ruc: ['El RUC no es válido.'],
                        legal_name: ['La razón social es obligatoria.'],
                    },
                },
                422,
            ),
        );

        const failure = await patchJson('/api/v1/companies/c1', { ruc: '123' }).catch((error: unknown) => error);

        expect(failure).toBeInstanceOf(ApiError);

        const error = failure as ApiError;

        expect(error.status).toBe(422);
        expect(error.message).toBe('El RUC no es válido.');
        expect(error.fieldError('ruc')).toBe('El RUC no es válido.');
        expect(error.fieldError('legal_name')).toBe('La razón social es obligatoria.');
        expect(error.fieldError('email')).toBeNull();
        expect(error.errors).toHaveProperty('ruc');
    });

    it('usa el mensaje de Laravel cuando la respuesta no trae detail', async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse({ message: 'No autorizado.' }, 403));

        const failure = await getJson('/api/v1/companies').catch((error: unknown) => error);

        expect(failure).toBeInstanceOf(ApiError);
        expect((failure as ApiError).message).toBe('No autorizado.');
        expect((failure as ApiError).errors).toEqual({});
    });

    it('cae a un mensaje genérico si el error no es JSON', async () => {
        fetchMock.mockResolvedValueOnce({
            ok: false,
            status: 500,
            json: async () => {
                throw new Error('no es JSON');
            },
            text: async () => 'Internal Server Error',
        } as unknown as Response);

        const failure = await getJson('/api/v1/companies').catch((error: unknown) => error);

        expect(failure).toBeInstanceOf(ApiError);
        expect((failure as ApiError).status).toBe(500);
        expect((failure as ApiError).message).toBe('La API respondió con estado 500.');
        expect((failure as ApiError).problem).toBeNull();
    });
});
