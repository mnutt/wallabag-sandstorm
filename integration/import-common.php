<?php
// Shared queue names come from upstream's service definitions, not request data.
function importTypes(): array
{
    $services = \Symfony\Component\Yaml\Yaml::parseFile('/opt/app/build/wallabag/app/config/services_redis.yml')['services'];
    $types = [];
    foreach (array_keys($services) as $id) {
        if (preg_match('/^wallabag_import\.consumer\.redis\.([a-z0-9_]+)$/D', $id, $match)) $types[] = $match[1];
    }
    return $types;
}

function importRedis(): \Predis\Client
{
    return new \Predis\Client(['scheme' => 'unix', 'path' => '/var/run/wallabag-redis.sock']);
}

function importCounts($redis): array
{
    $counts = ['queued' => 0, 'processing' => 0, 'failed' => 0];
    foreach (importTypes() as $type) {
        $queue = 'wallabag.import.' . $type;
        $counts['queued'] += $redis->llen($queue);
        $counts['processing'] += $redis->llen($queue . '-processing');
        $counts['failed'] += $redis->llen($queue . '-failed') + $redis->llen($queue . '-error');
    }
    return $counts;
}

function importActivity(string $session): void
{
    if ($session === '') return;
    // Argument arrays avoid invoking a shell. No article URLs or titles in notifications.
    $process = proc_open(['/opt/app/.sandstorm/utils/post-activity', '--type', '0',
        '--path', 'sandstorm/imports', '--caption', 'Import queued', $session],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) error_log('Could not post import-start activity.');
}

// This loopback endpoint is deliberately not exposed through nginx.
function importDisconnectNetwork(): bool
{
    $curl = curl_init('http://127.0.0.1:3001/_sandstorm/network/import-complete');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_PROXY => '',
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 12]);
    $result = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return $result !== false && $status === 204;
}
