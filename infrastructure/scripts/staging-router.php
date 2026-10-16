<?php
declare(strict_types=1);

// Only used by the isolated PHP development server, never by the live nginx site.
if (getenv('CHUNLIAN_QA_ENV') !== 'isolated-staging') {
    http_response_code(503);
    exit('Staging marker missing');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__qa_environment') {
    header('Content-Type: application/json');
    echo json_encode(['environment' => 'isolated-staging', 'database' => 'chunlian_qa_20261013']);
    return;
}
if (preg_match('#^/api/v1/(customer|merchant)/(.*)$#', (string) $path, $m)) {
    $_GET = array_merge($_GET, ['s' => '/plugins/index', 'pluginsname' => 'chunlianfresh',
        'pluginscontrol' => $m[1], 'pluginsaction' => 'dispatch', 'path' => $m[2]]);
    $_SERVER['SCRIPT_NAME'] = '/api.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/shopxo/public/api.php';
    require __DIR__ . '/shopxo/public/api.php';
    return;
}
if ($path && is_file(__DIR__ . '/shopxo/public' . $path)) {
    return false;
}
http_response_code(404);
echo 'Not found';
