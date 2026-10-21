<?php
require '/opt/app/build/wallabag/vendor/autoload.php';
require '/opt/app/integration/SandstormImportUpload.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$dir = sys_get_temp_dir() . '/import-upload-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
function upload($bytes) {
    $source = tempnam(sys_get_temp_dir(), 'upload-source-');
    file_put_contents($source, $bytes);
    return new Symfony\Component\HttpFoundation\File\UploadedFile($source, 'bookmarks.html', 'text/html', null, true);
}
function importer($callback) {
    return new class($callback) {
        public $path;
        public function __construct(private $callback) {}
        public function setFilepath($path) { $this->path = $path; return $this; }
        public function setMarkAsRead($value) { return $this; }
        public function import() { return ($this->callback)($this->path); }
    };
}
try {
    $first = importer(function ($firstPath) use ($dir) {
        $second = importer(function ($secondPath) use ($firstPath) {
            check($firstPath !== $secondPath, 'Concurrent uploads share a path');
            check(file_get_contents($firstPath) === 'first', 'First upload overwritten');
            check(file_get_contents($secondPath) === 'second', 'Second upload lost');
            return true;
        });
        check(SandstormImportUpload::run(upload('second'), $second, false, $dir), 'Second import failed');
        check(is_file($firstPath) && !is_file($second->path), 'Cleanup removed the wrong upload');
        return true;
    });
    check(SandstormImportUpload::run(upload('first'), $first, false, $dir), 'First import failed');
    $reject = importer(fn($path) => false);
    check(!SandstormImportUpload::run(upload('<script>alert(1)</script>'), $reject, false, $dir), 'Rejection lost');
    $throw = importer(function ($path) { throw new LogicException('Parse failure'); });
    try { SandstormImportUpload::run(upload('broken'), $throw, false, $dir); throw new RuntimeException('Exception lost'); }
    catch (LogicException $expected) {}
    check(glob($dir . '/*') === [], 'Uploads leaked after success/rejection/exception');
    check(!is_link('/opt/app/build/wallabag/web/uploads/import'), 'Upload directory exposed by web symlink');
    echo "PASS: isolated concurrent uploads and cleanup on success, rejection and exception.\n";
} finally {
    foreach (glob($dir . '/*') as $path) unlink($path);
    rmdir($dir);
}
