<?php

declare(strict_types=1);

it('expone el estado de la API', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure([
            'status',
            'app',
            'environment',
            'version',
            'timestamp',
        ]);
});
