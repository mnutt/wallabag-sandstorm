<?php
// Run in the build VM: php tests/browser-import.php [patched-source-root]
// Uses upstream importers and backup fixtures; no database or website access.
$source = $argv[1] ?? '/opt/app/build/wallabag';
require '/opt/app/build/wallabag/vendor/autoload.php';
require $source . '/src/Wallabag/ImportBundle/Import/BrowserImport.php';
require $source . '/src/Wallabag/ImportBundle/Import/FirefoxImport.php';
require $source . '/src/Wallabag/ImportBundle/Import/ChromeImport.php';

function expect($actual, $expected, string $message): void
{
    if ($actual !== $expected) throw new RuntimeException($message . ': got ' . var_export($actual, true));
}

function checkBackup(string $browser, array $backup, array $urls, bool $archived): void
{
    $class = new ReflectionClass('Wallabag\\ImportBundle\\Import\\' . $browser . 'Import');
    $import = $class->newInstanceWithoutConstructor();
    $user = new Wallabag\UserBundle\Entity\User();
    $id = new ReflectionProperty($user, 'id');
    $id->setAccessible(true);
    $id->setValue($user, 42);
    $import->setUser($user);
    $import->setMarkAsRead($archived);
    $producer = new class implements OldSound\RabbitMqBundle\RabbitMq\ProducerInterface {
        public $jobs = [];
        public function publish($msgBody, $routingKey = '', $additionalProperties = [])
        {
            $this->jobs[] = json_decode($msgBody, true, 512, JSON_THROW_ON_ERROR);
        }
    };
    $import->setProducer($producer);
    $file = tempnam(sys_get_temp_dir(), 'browser-import-');
    try {
        file_put_contents($file, json_encode($backup));
        expect($import->setFilepath($file)->import(), true, 'Upload succeeds');
    } finally {
        unlink($file);
    }
    expect(count($producer->jobs), count($urls), "$browser queues bookmarks individually");
    expect($import->getSummary()['queued'], count($urls), 'Summary counts bookmarks');
    $workerImport = $class->newInstanceWithoutConstructor();
    $prepare = $class->getMethod('prepareEntry');
    $prepare->setAccessible(true);
    $actualUrls = [];
    foreach ($producer->jobs as $job) {
        expect($workerImport->validateEntry($job), true, 'Consumer accepts each job');
        expect($job['userId'], 42, 'Job retains user');
        $data = $prepare->invoke($workerImport, $job);
        expect($data['is_archived'], (int) $archived, 'Fresh worker retains mark-as-read');
        $actualUrls[] = $data['url'];
    }
    expect($actualUrls, $urls, 'No bookmarks lost or duplicated');
}

$firefox = [];
$chrome = [];
$urls = [];
for ($i = 0; $i < 250; ++$i) {
    $url = 'https://example.org/bookmark/' . $i;
    $urls[] = $url;
    $firefox[] = ['guid' => 'bookmark-' . $i, 'id' => $i, 'title' => 'Bookmark ' . $i,
        'uri' => $url, 'dateAdded' => '1700000000000000', 'tags' => 'example'];
    $chrome[] = ['id' => (string) $i, 'name' => 'Bookmark ' . $i,
        'url' => $url, 'date_added' => '1700000000000000'];
}
foreach ([false, true] as $archived) {
    checkBackup('Firefox', ['guid' => 'root', 'id' => 1000, 'children' => [
        ['guid' => 'folder', 'id' => 1001, 'children' => [
            ['guid' => 'nested', 'id' => 1002, 'children' => $firefox],
            ['type' => 'text/x-moz-place-separator'], ['children' => []],
        ]],
    ]], $urls, $archived);
    checkBackup('Chrome', ['version' => 1, 'roots' => [
        'bookmark_bar' => ['name' => 'Bookmarks', 'children' => $chrome],
        'other' => ['children' => []],
    ]], $urls, $archived);
    checkBackup('Firefox', $firefox, $urls, $archived);
    checkBackup('Firefox', ['children' => []], [], $archived);
}
$fixture = json_decode(file_get_contents('/opt/app/build/wallabag/tests/Wallabag/ImportBundle/fixtures/firefox-bookmarks.json'), true);
$fixtureUrls = [];
$walk = function (array $node) use (&$walk, &$fixtureUrls) {
    if (isset($node['uri'])) { $fixtureUrls[] = $node['uri']; return; }
    foreach ($node as $child) if (is_array($child)) $walk($child);
};
$walk($fixture);
expect(count($fixtureUrls), 2, 'Upstream Firefox fixture has two bookmarks');
checkBackup('Firefox', $fixture, $fixtureUrls, false);
echo "PASS: nested Firefox/Chrome backups, 250 individual jobs, flat/empty backups, upstream fixture, and mark-as-read in fresh workers.\n";

foreach (['PocketHtml', 'Shaarli'] as $type) {
    $class = new ReflectionClass('Wallabag\\ImportBundle\\Import\\' . $type . 'Import');
    $import = $class->newInstanceWithoutConstructor();
    $method = $class->getMethod('prepareEntry');
    $method->setAccessible(true);
    foreach ([0, 1] as $archived) {
        $data = $method->invoke($import, ['url' => 'https://example.org/', 'title' => 'Test', 'tags' => '', 'created_at' => 1700000000, 'is_archived' => $archived]);
        expect($data['is_archived'], $archived, $type . ' fresh worker retains archive state');
    }
}
echo "PASS: Pocket HTML and Shaarli fresh-worker archive state.\n";
