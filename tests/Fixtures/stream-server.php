<?php

declare(strict_types=1);

/**
 * Bodies that arrive over time, for the streaming tests. None declares a
 * length unless its path says so, and each piece is flushed as written.
 *
 * `/frames/{n}/{ms}` sends n SSE frames {ms} apart. `/stall` sends one
 * frame and then nothing for three seconds. `/bytes/{n}` sends n bytes a
 * kilobyte at a time, and `/declared/{n}` the same n bytes behind a
 * matching `Content-Length`. `/until-abort?mark=` sends a frame every
 * 50 ms for up to five seconds and records in the mark file whether it
 * stopped because the client went away. `/flaky?mark=` answers 503 the
 * first time a mark file is used and streams `final` after that.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$mark = is_string($_GET['mark'] ?? null) ? $_GET['mark'] : null;

header('Content-Type: text/event-stream');

if (preg_match('#^/frames/(\d+)/(\d+)$#', $path, $matches) === 1) {
    for ($i = 0; $i < (int) $matches[1]; ++$i) {
        if ($i > 0) {
            usleep((int) $matches[2] * 1000);
        }

        echo "data: {$i}\n\n";
        flush();
    }

    return;
}

if ($path === '/stall') {
    echo "data: first\n\n";
    flush();
    sleep(3);
    echo "data: late\n\n";

    return;
}

if (preg_match('#^/(bytes|declared)/(\d+)$#', $path, $matches) === 1) {
    $total = (int) $matches[2];

    if ($matches[1] === 'declared') {
        header('Content-Length: ' . $total);
    }

    for ($sent = 0; $sent < $total; $sent += 1024) {
        echo str_repeat('x', min(1024, $total - $sent));
        flush();
    }

    return;
}

if ($path === '/until-abort' && $mark !== null) {
    ignore_user_abort(true);

    for ($i = 0; $i < 100; ++$i) {
        echo "data: {$i}\n\n";
        flush();

        if (connection_aborted() === 1) {
            file_put_contents($mark, "aborted after {$i} frames");

            return;
        }

        usleep(50_000);
    }

    file_put_contents($mark, 'completed');

    return;
}

if ($path === '/flaky' && $mark !== null) {
    if (!file_exists($mark)) {
        touch($mark);
        http_response_code(503);
        echo 'first-attempt';

        return;
    }

    echo 'fin';
    flush();
    usleep(50_000);
    echo 'al';

    return;
}

http_response_code(404);
