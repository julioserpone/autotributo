export class ApiError extends Error {
    public constructor(
        public readonly status: number,
        message: string,
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

export async function getJson<T>(path: string): Promise<T> {
    const response = await fetch(path, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new ApiError(response.status, `La API respondió con estado ${response.status}.`);
    }

    return (await response.json()) as T;
}

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export async function postJson<T>(path: string, body: unknown): Promise<T> {
    const response = await fetch(path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        let message = `La API respondió con estado ${response.status}.`;

        try {
            const data = (await response.json()) as { message?: string };
            if (data.message) {
                message = data.message;
            }
        } catch {
            // El cuerpo no es JSON; se conserva el mensaje genérico.
        }

        throw new ApiError(response.status, message);
    }

    return (await response.json()) as T;
}
