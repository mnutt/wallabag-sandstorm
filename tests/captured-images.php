<?php
// Run in the build VM: php tests/captured-images.php
require '/opt/app/build/wallabag/vendor/autoload.php';
require '/opt/app/integration/SandstormCapturedImages.php';
use Wallabag\CoreBundle\Helper\SandstormCapturedImages as Captured;
use Wallabag\CoreBundle\Helper\DownloadImages;
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$dir = sys_get_temp_dir() . '/captured-images-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
try {
    $image = imagecreatetruecolor(3000, 1500);
    ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
    $data = 'data:image/png;base64,' . base64_encode($bytes);
    $html = '<article><h1>Captured article</h1>' . str_repeat('<p>Article content that must remain readable after browser capture and image processing.</p>', 10) .
        '<img src="' . $data . '"><img src="' . $data . '">' .
        '<img src="https://external.invalid/image.png"><img src="data:image/svg+xml;base64,' . base64_encode('<svg/>') . '">' .
        '<script>alert(1)</script><a href="javascript:alert(1)">bad</a></article>';
    [$prepared, $images] = Captured::prepare($html);
    check(count($images) === 2, 'Embedded duplicates not deduplicated');
    check(!str_contains($prepared, 'data:image'), 'Embedded input retained');
    $graby = new Graby\Graby();
    $clean = $graby->cleanupHtml($prepared, 'https://example.org/article');
    check(!preg_match('~<script|(?:href|src)=["\']\s*javascript:~i', $clean), 'Sanitization bypassed');
    check(str_contains($clean, array_key_first($images)), 'Sanitizer lost image placeholder');
    $client = new class implements \Psr\Http\Client\ClientInterface {
        public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
            throw new RuntimeException('Captured images must never fetch URLs');
        }
    };
    $download = new DownloadImages($client, $dir, '', new \Psr\Log\NullLogger());
    $entry = new class($clean, array_key_first($images)) {
        public function __construct(public $content, public $preview) {}
        public function getId() { return 71; }
        public function getContent() { return $this->content; }
        public function setContent($content) { $this->content = $content; }
        public function getPreviewPicture() { return $this->preview; }
        public function setPreviewPicture($preview) { $this->preview = $preview; }
    };
    Captured::save($entry, $download, $images);
    check(!str_contains($entry->content, 'sandstorm-capture.invalid'), 'Placeholder retained');
    check(!str_contains($entry->content, 'https://external.invalid/image.png'), 'Remote image retained');
    check(str_ends_with($entry->preview, '-thumb.png'), 'Missing thumbnail preview');
    $thumb = getimagesize(str_replace('/assets/images', $dir, $entry->preview));
    $main = getimagesize(str_replace(['/assets/images', '-thumb'], [$dir, ''], $entry->preview));
    check([$thumb[0], $thumb[1]] === [640, 320], 'Wrong thumbnail dimensions');
    check([$main[0], $main[1]] === [1920, 960], 'Wrong article dimensions');
    [$responsive] = Captured::prepare('<picture><source srcset="https://cdn.invalid/a.png"><img src="https://cdn.invalid/b.png" srcset="https://cdn.invalid/c.png 2x"></picture>');
    check(!str_contains($responsive, 'https://cdn.invalid'), 'Responsive images retained remote URLs');
    $preview = $entry->preview;
    Captured::save($entry, $download, []);
    check($entry->preview === $preview, 'Metadata edit changed preview');
    [$badHtml, $badImages] = Captured::prepare('<img src="data:image/png;base64,' . base64_encode('not an image') . '">');
    $entry->content = $badHtml; $entry->preview = array_key_first($badImages);
    Captured::save($entry, $download, $badImages);
    check($entry->preview === null && !str_contains($entry->content, 'sandstorm-capture.invalid'), 'Invalid image retained');
    foreach (['data:image/svg+xml;base64,PHN2Zy8+', 'data:image/png;base64,' . base64_encode('bad decoder input'), 'data:image/png;base64,' . base64_encode(substr_replace($bytes, pack('NN', 100000, 100000), 16, 8)), 'data:image/png;base64,' . str_repeat('a', 4 * 1024 * 1024 + 256)] as $bad) {
        $fallback = 'https://example.org/image?a=1&b=2';
        [$html, $images] = Captured::prepare('<img data-sandstorm-original-src="' . htmlspecialchars($fallback, ENT_QUOTES) . '" src="' . $bad . '">');
        $entry->content = $html; $entry->preview = array_key_first($images);
        Captured::save($entry, $download, $images);
        check(!str_contains($entry->content, 'https://example.org/image'), 'Rejected capture retained remote fallback');
        check(!str_contains($entry->content, 'data-sandstorm-original-src'), 'Transport attribute leaked');
    }
    [$html, $images] = Captured::prepare('<img data-sandstorm-original-src="https://example.org/fallback.png" src="' . $data . '">');
    $entry->content = $html; $entry->preview = array_key_first($images);
    $blocked = $download->getBaseFolder() . '/' . $download->getRelativePath($entry->getId()) . '/capture-' . hash('sha256', $bytes) . '.png';
    unlink($blocked);
    mkdir($blocked); // Force the atomic rename to fail.
    set_error_handler(fn($severity, $message) => str_starts_with($message, 'rename('));
    try { Captured::save($entry, $download, $images); } finally { restore_error_handler(); }
    check(!str_contains($entry->content, 'https://example.org/fallback.png'), 'Write failure retained remote fallback');
    check($entry->preview === null, 'Write failure retained remote preview');
    foreach (['javascript:alert(1)', 'file:///etc/passwd', 'https://user:password@example.org/a'] as $fallback) {
        [$html, $images] = Captured::prepare('<img data-sandstorm-original-src="' . $fallback . '" src="data:image/png;base64,YmFk">');
        $entry->content = $html; $entry->preview = array_key_first($images);
        Captured::save($entry, $download, $images);
        check(!str_contains($entry->content, $fallback), 'Unsafe fallback accepted');
    }
    $entry->preview = 'https://cdn.invalid/preview.png';
    Captured::save($entry, $download, []);
    check($entry->preview === null, 'Capture without embedded images kept remote preview');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="670" height="184" onload="alert(1)"><script>alert(1)</script><image href="https://remote.invalid/tracker.png"/><rect width="670" height="184" fill="red"/></svg>';
    [$html, $images] = Captured::prepare('<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '">');
    $entry->content = $graby->cleanupHtml($html, 'https://example.org/article');
    $entry->preview = array_key_first($images);
    Captured::save($entry, $download, $images);
    check(str_ends_with($entry->preview, '.svg'), 'SVG preview was not saved locally');
    check(str_contains($entry->content, $entry->preview), 'SVG article URL differs from preview');
    $savedSvg = file_get_contents(str_replace('/assets/images', $dir, $entry->preview));
    check(str_contains($savedSvg, '<rect'), 'SVG drawing lost');
    check(!preg_match('/<script|onload|remote\.invalid/i', $savedSvg), 'SVG active content or remote reference retained');
    foreach (['<svg', '<html xmlns="http://www.w3.org/1999/xhtml"/>', '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'] as $badSvg) {
        [$html, $images] = Captured::prepare('<img src="data:image/svg+xml;base64,' . base64_encode($badSvg) . '">');
        $entry->content = $html; $entry->preview = array_key_first($images);
        Captured::save($entry, $download, $images);
        check($entry->preview === null, 'Malformed or entity-bearing SVG accepted');
        check(!str_contains($entry->content, '/assets/images/'), 'Rejected SVG stored');
    }
    echo "PASS: embedded uploads, sanitization, deduplication, resizing, thumbnails, sanitized SVG storage, SVG previews, DTD rejection, remote image removal, invalid input and no network requests.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($dir);
}
