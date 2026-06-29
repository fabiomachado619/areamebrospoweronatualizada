<?php

declare(strict_types=1);

$logFile = dirname(__DIR__).'/storage/logs/manual-outbound-captures.log';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

if ($uri === '/capture' || str_starts_with($uri, '/capture?')) {
    $body = file_get_contents('php://input') ?: '';
    file_put_contents(
        $logFile,
        date('c')."\n".$body."\n---\n",
        FILE_APPEND
    );
    header('Content-Type: text/plain');
    http_response_code(200);
    echo 'ok';

    return true;
}

http_response_code(404);
echo 'not found';

return true;
