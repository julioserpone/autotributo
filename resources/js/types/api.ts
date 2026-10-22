export interface HealthResponse {
    status: 'ok';
    app: string;
    environment: string;
    version: string;
    timestamp: string;
}
