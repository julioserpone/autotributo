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
