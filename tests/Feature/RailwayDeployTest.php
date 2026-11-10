<?php

it('points Railway at the single-container image and the health check', function () {
    $railway = file_get_contents(base_path('railway.toml'));
    $start = file_get_contents(base_path('docker/render/start.sh'));
    $dockerfile = file_get_contents(base_path('Dockerfile.render'));
    $readme = file_get_contents(base_path('README.md'));

    expect($railway)->toContain('dockerfilePath = "Dockerfile.render"')
        ->and($railway)->toContain('healthcheckPath = "/health"')
        ->and($start)->toContain('RAILWAY_PUBLIC_DOMAIN')
        ->and($start)->toContain('DATABASE_PRIVATE_URL')
        ->and($dockerfile)->toContain('ENV PORT=')
        ->and($readme)->toContain('APP_KEY')
        ->and($readme)->toContain('Railway');
});
