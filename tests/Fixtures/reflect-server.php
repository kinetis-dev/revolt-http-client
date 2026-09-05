<?php

declare(strict_types=1);

/**
 * Reflects the request back as JSON so a test can assert on what the
 * client actually sent — method, path, query, headers, and body.
 *
 * `/status/{code}` answers with that status instead, for the error-status
 * paths, `/not-json` returns a body that isn't JSON at all, `/big-int`
 * returns an integer wider than PHP's own int type, and `/redirect`
 * answers 302 pointing at `/me`, so a test can prove the client stops
 * there rather than following it.
 *
 * `/bytes/{n}` answers with n bytes and the Content-Length that goes
 * with them. `/stream/{n}` answers with the same n bytes flushed a
 * kilobyte at a time, which leaves the built-in server no length to
 * declare — the shape that makes a client measure a transfer as it
 * arrives rather than believe a header. `/gzip/{n}` answers with n
 * highly compressible bytes gzipped, whatever the request asked to
 * accept, which is the shape where bytes on the wire and bytes in
 * memory come apart.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (preg_match('#^/status/(\d{3})$#', $path, $matches) === 1) {
    http_response_code((int) $matches[1]);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'deliberate failure'], JSON_THROW_ON_ERROR);

    return;
}

if ($path === '/redirect') {
    http_response_code(302);
    header('Location: /me');
    header('Content-Type: application/json');
    echo json_encode(['redirected' => true], JSON_THROW_ON_ERROR);

    return;
}

if (preg_match('#^/bytes/(\d+)$#', $path, $matches) === 1) {
    $total = (int) $matches[1];

    header('Content-Type: application/octet-stream');
    // Declared rather than left to the server, which frames a response
    // it has not finished buffering by closing the connection instead.
    header('Content-Length: ' . $total);
    echo str_repeat('x', $total);

    return;
}

if (preg_match('#^/gzip/(\d+)$#', $path, $matches) === 1) {
    // Compressed regardless of Accept-Encoding: a client cannot make a
    // server honour identity, only refuse to inflate what comes back.
    $compressed = gzencode(str_repeat('A', (int) $matches[1]), 9);

    header('Content-Type: text/plain');
    header('Content-Encoding: gzip');
    header('Content-Length: ' . strlen($compressed));
    echo $compressed;

    return;
}

if (preg_match('#^/stream/(\d+)$#', $path, $matches) === 1) {
    header('Content-Type: application/octet-stream');
    $total = (int) $matches[1];

    for ($sent = 0; $sent < $total; $sent += 1024) {
        echo str_repeat('x', min(1024, $total - $sent));
        flush();
    }

    return;
}

if ($path === '/big-int') {
    header('Content-Type: application/json');
    echo '{"id":12345678901234567890123,"safe":9007199254740993}';

    return;
}

if ($path === '/not-json') {
    header('Content-Type: text/plain');
    echo 'definitely not json';

    return;
}

if ($path === '/slow') {
    usleep(400_000);
    header('Content-Type: application/json');
    echo json_encode(['slept' => true], JSON_THROW_ON_ERROR);

    return;
}

$headers = [];

foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
    }
}

header('Content-Type: application/json');
header('X-Reflected: yes');

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $path,
    'query' => $_GET,
    'headers' => $headers,
    'contentType' => $_SERVER['CONTENT_TYPE'] ?? '',
    'body' => file_get_contents('php://input'),
    'nested' => ['items' => [['id' => 7]]],
], JSON_THROW_ON_ERROR);
