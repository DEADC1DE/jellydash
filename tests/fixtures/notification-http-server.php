<?php

declare(strict_types=1);

// A one-request loopback fixture. It never connects to an external service.
[$script, $directory, $mode] = $argv;
$context = stream_context_create();
if ($mode === 'tls') {
    $config = $directory . '/openssl.cnf';
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n");
    $options = ['config' => $config, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($options);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, $options);
    $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
    openssl_x509_export($certificate, $pem);
    openssl_pkey_export($key, $privateKey, options: $options);
    file_put_contents($directory . '/tls.pem', $pem . $privateKey);
    $context = stream_context_create(['ssl' => ['local_cert' => $directory . '/tls.pem']]);
}
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    exit(1);
}
$address = stream_socket_get_name($server, false);
file_put_contents($directory . '/ready', $address);
$client = stream_socket_accept($server, 10);
if ($client === false) {
    exit(2);
}
stream_set_timeout($client, 10);
if ($mode === 'tls') {
    $enabled = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
    if ($enabled === true) {
        stream_set_timeout($client, 1);
        $line = @fgets($client);
        if (is_string($line) && $line !== '') {
            file_put_contents($directory . '/request.json', $line);
        }
    }
    fclose($client);
    fclose($server);
    exit(0);
}
$requestLine = trim((string) fgets($client));
$headers = [];
while (($line = fgets($client)) !== false && trim($line) !== '') {
    [$name, $value] = explode(':', $line, 2);
    $headers[strtolower($name)] = trim($value);
}
$body = '';
$remaining = (int) ($headers['content-length'] ?? 0);
while ($remaining > 0 && ($chunk = fread($client, $remaining)) !== false && $chunk !== '') {
    $body .= $chunk;
    $remaining -= strlen($chunk);
}
file_put_contents($directory . '/request.json', json_encode(['line' => $requestLine, 'headers' => $headers, 'body' => $body]));
if ($mode === 'timeout') {
    sleep(9);
} elseif ($mode !== 'disconnect') {
    $status = ctype_digit($mode) ? (int) $mode : ($mode === 'redirect' ? 302 : 200);
    $body = $mode === 'large' ? str_repeat('x', 65537) : '{"id":"test-id","event":"message"}';
    $extra = $mode === 'redirect' ? "Location: http://$address/followed\r\n" : '';
    fwrite($client, "HTTP/1.1 $status Fixture\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n" . $extra . "Connection: close\r\n\r\n" . $body);
}
fclose($client);
if ($mode === 'redirect') {
    $next = @stream_socket_accept($server, 1);
    if ($next !== false) {
        file_put_contents($directory . '/followed', 'yes');
        fclose($next);
    }
}
fclose($server);
