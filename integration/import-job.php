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
try {
    $kernel = new AppKernel('prod', false);
    $kernel->boot();
    $consumer = $kernel->getContainer()->get('wallabag_import.consumer.redis.' . $type);
    if (!$consumer->manage($job)) exit(1);
    $kernel->shutdown();
} catch (\Throwable $error) {
    // Details can contain private article URLs/content; retain the job for retry.
    fwrite(STDERR, "Import entry failed; retained for retry.\n");
    exit(1);
}
