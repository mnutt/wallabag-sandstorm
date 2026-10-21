<?php
// Single supervisor for all upstream import queues. Redis AOF owns durable jobs;
// atomic list moves retain the in-flight entry across a crash or cancellation.
require '/opt/app/build/wallabag/vendor/autoload.php';
require __DIR__ . '/import-common.php';
$lock = fopen('/var/lib/wallabag/import-worker.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(1);
$redis = importRedis();
$types = importTypes();
$wake = null;
$wakeInput = null;
$child = null;
$queue = null;
$started = 0;
$idleSince = null;
$stopping = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use (&$stopping) { $stopping = true; });
pcntl_signal(SIGINT, function () use (&$stopping) { $stopping = true; });

function recoverImports($redis, array $types): void
{
    foreach ($types as $type) {
        $queue = 'wallabag.import.' . $type;
        while ($redis->rpoplpush($queue . '-processing', $queue) !== null) {}
    }
}

function releaseWake(&$wake, &$input): void
{
    if (is_resource($input)) fclose($input);
    if (is_resource($wake)) proc_close($wake);
    $wake = $input = null;
}

function importNotification(bool $failed): bool
{
    $process = proc_open(['/opt/app/build/import-notify', $failed ? 'attention' : 'complete'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => STDERR], $pipes);
    return is_resource($process) && proc_close($process) === 0;
}

recoverImports($redis, $types);
try {
    while (!$stopping) {
        if ($child !== null) {
            $status = proc_get_status($child);
            if (!$status['running']) {
                proc_close($child);
                $child = null;
                // Both removal and failed-job retention must survive together.
                if ($status['exitcode'] === 0) $redis->rpop($queue . '-processing');
                else $redis->rpoplpush($queue . '-processing', $queue . '-failed');
                $redis->incr('sandstorm.import.processed');
            }
        }
        if ($wake !== null && !proc_get_status($wake)['running']) {
            // Respect cancellation in Sandstorm's notification UI. Do not
            // immediately obtain another lock; only explicit Resume clears this.
            $redis->set('sandstorm.import.paused', '1');
            if ($child !== null) {
                proc_terminate($child, SIGKILL);
                proc_close($child);
                $child = null;
                recoverImports($redis, $types);
            }
            releaseWake($wake, $wakeInput);
        }
        if ($child !== null && time() - $started > 300) {
            // Includes waiting for new website grants. Bound both resource use
            // and wake-lock lifetime when nobody can answer a Powerbox prompt.
            proc_terminate($child, SIGKILL);
            proc_close($child);
            $child = null;
            $redis->rpoplpush($queue . '-processing', $queue . '-failed');
            $redis->incr('sandstorm.import.processed');
        }
        if ($redis->exists('sandstorm.import.paused')) {
            if ($child !== null) {
                proc_terminate($child, SIGKILL);
                proc_close($child);
                $child = null;
                recoverImports($redis, $types);
            }
            releaseWake($wake, $wakeInput);
            sleep(1);
            continue;
        }
        if ($child !== null) { usleep(200000); continue; }

        $counts = importCounts($redis);
        if ($counts['queued'] > 0) {
            $idleSince = null;
            if ($wake === null) {
                $wake = proc_open(['/opt/app/build/import-notify', 'awake'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
                if (!is_resource($wake)) throw new RuntimeException('Could not start import wake lock.');
                $wakeInput = $pipes[0];
                stream_set_timeout($pipes[1], 12);
                $ready = fgets($pipes[1]);
                fclose($pipes[1]);
                if ($ready !== "ready\n") {
                    $redis->set('sandstorm.import.paused', '1');
                    releaseWake($wake, $wakeInput);
                }
                continue;
            }
            foreach ($types as $type) {
                $queue = 'wallabag.import.' . $type;
                if ($redis->rpoplpush($queue, $queue . '-processing') === null) continue;
                $redis->set('sandstorm.import.active', '1');
                $child = proc_open(['/usr/bin/php', '/opt/app/integration/import-job.php', $type],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => STDERR], $pipes);
                if (!is_resource($child)) throw new RuntimeException('Could not start import job.');
                $started = time();
                break;
            }
        } else {
            // A brief quiet interval coalesces jobs queued by the same upload.
            // Publishers mark their request active until its response completes.
            $redis->zremrangebyscore('sandstorm.import.publishing', '-inf', time());
            if ($redis->zcard('sandstorm.import.publishing') > 0) { sleep(1); continue; }
            $idleSince = $idleSince ?? time();
            if (time() - $idleSince >= 3) {
                if ($redis->exists('sandstorm.import.active')) {
                    $failed = $counts['failed'] > 0;
                    // A failed RPC can already have delivered a notification.
                    // Mark the attempt first to avoid duplicate notifications
                    // on retries/restarts; durable progress remains in the UI.
                    $redis->del(['sandstorm.import.active']);
                    if (!importNotification($failed)) {
                        fwrite(STDERR, "Import finished, but Sandstorm could not confirm its notification. Check import progress.\n");
                    }
                    $redis->set('sandstorm.import.last', $failed ? 'attention' : 'complete');
                    $idleSince = time() + 57;
                }
                releaseWake($wake, $wakeInput);
            }
            sleep(1);
        }
    }
} finally {
    if (is_resource($child)) { proc_terminate($child, SIGKILL); proc_close($child); }
    releaseWake($wake, $wakeInput);
    // Leave in-flight work in Redis for recovery on the next start.
}
