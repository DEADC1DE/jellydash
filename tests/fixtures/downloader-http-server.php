<?php

declare(strict_types=1);

[$script, $directory, $mode] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!is_resource($server)) {
    file_put_contents($directory . '/stderr', $errorCode . ':' . $errorMessage);
    exit(1);
}
file_put_contents($directory . '/ready', stream_socket_get_name($server, false));
$readRequest = static function ($connection): string {
    $request = '';
    while (!str_contains($request, "\r\n\r\n") && !feof($connection)) {
        $request .= (string) fread($connection, 8192);
    }
    return $request;
};
if ($mode === 'multi') {
    $connections = [];
    $requests = [];
    for ($index = 0; $index < 2; ++$index) {
        $connections[$index] = stream_socket_accept($server, 4);
        if (!is_resource($connections[$index])) {
            exit(3);
        }
        $requests[$index] = $readRequest($connections[$index]);
    }
    foreach ($connections as $index => $client) {
        $body = str_contains($requests[$index], 'mode=history') ? '{"kind":"history"}' : '{"kind":"queue"}';
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        fclose($client);
    }
    file_put_contents($directory . '/requests', implode("\n---\n", $requests));
    fclose($server);
    exit(0);
}
$connection = stream_socket_accept($server, 10);
if (!is_resource($connection)) {
    exit(2);
}
$request = $readRequest($connection);
file_put_contents($directory . '/request', $request);

if ($mode === 'history-stream') {
    $row = json_encode(['NZBID' => 1, 'Name' => str_repeat('x', 1000)], JSON_THROW_ON_ERROR);
    $prefix = '{"id":1,"result":[';
    $suffix = '],"error":null}';
    $length = strlen($prefix) + strlen($suffix) + 5999 + 6000 * strlen($row);
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: " . $length . "\r\nConnection: close\r\n\r\n" . $prefix);
    for ($i = 0; $i < 6000; ++$i) {
        fwrite($connection, ($i ? ',' : '') . $row);
    }
    fwrite($connection, $suffix);
} elseif ($mode === 'history-auth') {
    fwrite($connection, "HTTP/1.1 401 Unauthorized\r\nContent-Length: 12\r\nConnection: close\r\n\r\nUnauthorized");
} elseif ($mode === 'timeout') {
    sleep(6);
    $body = '{}';
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n" . $body);
} elseif ($mode === 'large') {
    $body = str_repeat('x', 3 * 1024 * 1024);
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
} elseif ($mode === 'redirect') {
    fwrite($connection, "HTTP/1.1 302 Found\r\nLocation: /api?mode=queue\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
} else {
    $body = '{"queue":{"slots":[]}}';
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nX-Test: safe\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
}
fclose($connection);
fclose($server);
