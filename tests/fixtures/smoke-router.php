<?php

// Stand-in for a deployed FIBES host, used by SmokeCheckScriptTest.
// SMOKE_STUB=good answers like a healthy deploy; SMOKE_STUB=bad breaks several checks.
$bad = getenv('SMOKE_STUB') === 'bad';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if (str_starts_with($path, '/_http/')) {
    header('Location: https://smoke.test'.substr($path, 6), true, 301);

    return true;
}

if (! $bad) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

switch (true) {
    case $path === '/login':
        echo '<div id="app" data-page="{}"></div>';
        break;
    case $path === '/up':
        echo 'ok';
        break;
    case $path === '/health':
        header('Content-Type: application/json');
        echo $bad ? '{"status":"down"}' : '{"status":"ok","database":"ok"}';
        http_response_code($bad ? 503 : 200);
        break;
    case $path === '/build' || $path === '/storage/':
        header('Location: '.($bad ? 'http://smoke.test:8080/build/' : '/build/'), true, 301);
        break;
    case $path === '/api/v1/hooks/erpnext':
        http_response_code(503);
        break;
    case $path === '/api/v1/hooks/web-form' && $bad:
        http_response_code(500);
        break;
    case str_starts_with($path, '/api/') || str_starts_with($path, '/exports/'):
        http_response_code($method === 'GET' && str_contains($path, 'whatsapp') ? 403 : 401);
        break;
    default:
        http_response_code(404);
}

return true;
