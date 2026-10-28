<?php
// One PHP process per entry bounds memory and discards failed Doctrine state.
require '/opt/app/build/wallabag/vendor/autoload.php';
require __DIR__ . '/import-common.php';
$type = $argv[1] ?? '';
if (!in_array($type, importTypes(), true)) exit(2);
$redis = importRedis();
$queue = 'wallabag.import.' . $type;
$job = $redis->lindex($queue . '-processing', 0);
if ($job === null) exit(2);
putenv('SANDSTORM_IMPORT_WORKER=1');
putenv('SANDSTORM_IMPORT_JOB_KEY=' . hash('sha256', $type . "\0" . $job));
$data = json_decode($job, true) ?: [];
$url = '(missing URL)';
foreach (['resolved_url', 'given_url', 'uri', 'url', 'article__url', 'href'] as $key) {
    if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
        $url = $data[$key];
        break;
    }
}
// JSON quoting keeps URLs and error messages on one log line.
$label = 'type=' . $type . ' url=' . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$started = microtime(true);
$log = static function (string $message) use ($label, $started): void {
    fwrite(STDERR, '[import] ' . gmdate('Y-m-d\TH:i:s\Z') . ' ' . $label . ' elapsed=' .
        number_format(microtime(true) - $started, 2, '.', '') . 's ' . $message . "\n");
};
if (($unsupportedScheme = importUnsupportedScheme($url)) !== null) {
    $log('Skipped: unsupported URL scheme ' . $unsupportedScheme . '; not a web article');
    exit(0);
}
$log('Processing entry');
try {
    $kernel = new AppKernel('prod', false);
    $kernel->boot();
    // Forward diagnostics for this entry, including errors Graby catches before
    // returning its generic extraction-failure page. Do not dump article bodies.
    $handler = importDiagnosticHandler($label);
    $logger = new \Monolog\Logger('fetch', [$handler]);
    // Graby propagates this logger to its extractor and HTTP fetcher.
    $kernel->getContainer()->get(\Graby\Graby::class)->setLogger($logger);
    if ($kernel->getContainer()->has('logger')) {
        $appLogger = $kernel->getContainer()->get('logger');
        if ($appLogger instanceof \Monolog\Logger) $appLogger->pushHandler($handler);
    }
    $consumer = $kernel->getContainer()->get('wallabag_import.consumer.redis.' . $type);
    if (!$consumer->manage($job)) {
        $log('Failed: consumer could not save entry; retained for retry');
        exit(1);
    }
    $log('Completed (imported or skipped by importer)');
    $kernel->shutdown();
} catch (\Throwable $error) {
    $log('Failed: ' . get_class($error) . ' ' . json_encode($error->getMessage(), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . '; retained for retry');
    exit(1);
}
