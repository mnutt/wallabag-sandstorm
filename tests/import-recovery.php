<?php
require '/opt/app/build/wallabag/vendor/autoload.php';
require '/opt/app/integration/SandstormImportState.php';
use Wallabag\CoreBundle\Entity\Entry;
use Wallabag\UserBundle\Entity\User;
use Wallabag\ImportBundle\Consumer\RedisEntryConsumer;
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function property($object, $name, $value) {
    $p = new ReflectionProperty($object, $name); $p->setAccessible(true); $p->setValue($object, $value);
}
$db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE entries (id INTEGER PRIMARY KEY)');
$db->executeStatement('CREATE TABLE sandstorm_import_pending (job_key TEXT PRIMARY KEY, user_id INTEGER NOT NULL, entry_id INTEGER NOT NULL)');
$user = new User(); property($user, 'id', 42);
$entry = new Entry($user); property($entry, 'id', 7); $entry->setUrl('https://example.org/article');
$em = new class($db, $entry) {
    public function __construct(public $db, public $entry) {}
    public function getConnection() { return $this->db; }
    public function flush() { $this->db->insert('entries', ['id' => $this->entry->getId()]); }
    public function find($class, $id) { return $this->db->fetchOne('SELECT id FROM entries WHERE id = ?', [$id]) === false ? null : $this->entry; }
    public function clear($class) {}
};
$import = new class($entry) {
    public $calls = 0;
    public function __construct(private $entry) {}
    public function setUser($user) {}
    public function validateEntry($data) { return true; }
    public function parseEntry($data) { return ++$this->calls === 1 ? $this->entry : null; }
};
$dispatcher = new class($db) {
    public $calls = 0;
    public function __construct(private $db) {}
    public function dispatch($event, $name) {
        check(!$this->db->isTransactionActive(), 'Network-dependent events run inside a DB transaction');
        if (++$this->calls === 1) throw new RuntimeException('Simulated interruption during image download');
    }
};
$repository = new class($user) {
    public function __construct(private $user) {}
    public function find($id) { return $this->user; }
};
$consumer = (new ReflectionClass(RedisEntryConsumer::class))->newInstanceWithoutConstructor();
foreach (['em' => $em, 'import' => $import, 'eventDispatcher' => $dispatcher, 'userRepository' => $repository, 'logger' => new Psr\Log\NullLogger()] as $key => $value) property($consumer, $key, $value);
putenv('SANDSTORM_IMPORT_JOB_KEY=test-job');
$job = json_encode(['userId' => 42]);
check(!$consumer->manage($job), 'Interrupted import acknowledged');
check((int) $db->fetchOne('SELECT COUNT(*) FROM sandstorm_import_pending') === 1, 'Saved entry has no durable checkpoint');
check($consumer->manage($job), 'Retry failed');
check($import->calls === 1 && $dispatcher->calls === 2, 'Retry skipped unfinished images or parsed entry again');
check((int) $db->fetchOne('SELECT COUNT(*) FROM sandstorm_import_pending') === 0, 'Completed checkpoint leaked');
check($consumer->manage($job) && $dispatcher->calls === 2, 'Ordinary duplicate reprocessed');
$db->insert('sandstorm_import_pending', ['job_key' => 'test-job', 'user_id' => 42, 'entry_id' => 7]);
$db->delete('entries', ['id' => 7]);
check($consumer->manage($job) && $import->calls === 2, 'Deleted entry recreated on retry');
// A marker-insert failure must roll back the preceding entry save too.
try {
    (new SandstormImportState($em, 'test-job'))->save($entry, 42);
    throw new LogicException('Expected checkpoint collision');
} catch (Doctrine\DBAL\Exception\UniqueConstraintViolationException $expected) {}
check((int) $db->fetchOne('SELECT COUNT(*) FROM entries') === 0, 'Entry committed without its checkpoint');
check(!$db->isTransactionActive(), 'Rollback left a transaction open');
putenv('SANDSTORM_IMPORT_JOB_KEY');
echo "PASS: interrupted save-event recovery, duplicate handling, deletion, atomic rollback and no network inside transactions.\n";

// Exercise the actual startup migration against an existing library, twice.
$path = tempnam(sys_get_temp_dir(), 'import-migration-');
try {
    copy('/opt/app/build/library-seed.sqlite', $path);
    for ($i = 0; $i < 2; ++$i) {
        $process = proc_open([PHP_BINARY, '/opt/app/integration/import-settings.php'], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, null, ['WALLABAG_DATABASE' => $path]);
        check(proc_close($process) === 0, 'Startup migration failed');
    }
    $pdo = new PDO('sqlite:' . $path);
    check($pdo->query('SELECT COUNT(*) FROM sandstorm_import_pending')->fetchColumn() == 0, 'Checkpoint table missing');
    $pdo = null;
} finally { unlink($path); }
echo "PASS: startup migration creates the checkpoint table and is repeatable.\n";
