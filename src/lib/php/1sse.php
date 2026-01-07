<?php
while (ob_get_level()) ob_end_clean();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$path = __DIR__.'/events.txt';

$fp = fopen($path,'r');
stream_set_blocking($fp, false);
fseek($fp, 0, SEEK_END);     // spring gamle events over

$lastPing = time();

while (true) {
    if (connection_aborted()) exit;

    clearstatcache(true, $path);

    $data = stream_get_contents($fp);
    if ($data !== false && $data !== '') {
        foreach (explode("\n", trim($data)) as $line) {
            if ($line !== '') {
                echo "data: {$line}\n\n";
            }
        }
        flush();
        $lastPing = time();
    }

    if (time() - $lastPing > 15) {
        echo ": ping\n\n";
        flush();
        $lastPing = time();
    }

    usleep(200000);
}