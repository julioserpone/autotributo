<?php

declare(strict_types=1);

it('ejecuta PHP 8.5 o superior', function (): void {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80500);
});
