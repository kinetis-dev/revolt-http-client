<?php

declare(strict_types=1);

/**
 * Reflects the request back as JSON so a test can assert on what the
 * client actually sent — method, path, query, headers, and body.
 *
 * `/status/{code}` answers with that status instead, for the error-status
 * paths, and `/not-json` returns a body that isn't JSON at all.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (preg_match('#^/status/(\d{3})$#', $path, $matches) === 1) {
    http_response_code((int) $matches[1]);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'deliberate failure'], JSON_THROW_ON_ERROR);

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
