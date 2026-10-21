<?php
// Run in the build VM: php tests/images.php
require '/opt/app/build/wallabag/vendor/autoload.php';
require '/opt/app/integration/SandstormImageProcessor.php';

use Wallabag\CoreBundle\Helper\SandstormImageProcessor as Images;
use Wallabag\CoreBundle\Helper\DownloadImages;
use GuzzleHttp\Psr7\Response;

function check($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function dimensions(string $path, int $width, int $height): void {
    $size = getimagesize($path);
    check($size && $size[0] === $width && $size[1] === $height, 'Unexpected dimensions: ' . $path);
}
function encoded($image, string $format): string {
    ob_start();
    try {
        $function = 'image' . $format;
        $function($image);
        return ob_get_contents();
    } finally { ob_end_clean(); }
}
function withOrientation(string $jpeg, int $orientation): string {
    $tiff = 'II' . pack('vVv', 42, 8, 1) . pack('vvVv', 0x112, 3, 1, $orientation) . "\0\0" . pack('V', 0);
    $exif = "Exif\0\0" . $tiff;
    return substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
}

$dir = sys_get_temp_dir() . '/wallabag-images-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
try {
    $large = imagecreatetruecolor(3000, 1500);
    imagealphablending($large, false);
    imagesavealpha($large, true);
    imagefill($large, 0, 0, imagecolorallocatealpha($large, 0, 0, 0, 127));
    imagefilledrectangle($large, 1000, 500, 2000, 1000, imagecolorallocate($large, 240, 30, 20));
    $png = encoded($large, 'png');
    check(Images::save($png, "$dir/large") === 'png', 'PNG conversion failed');
    dimensions("$dir/large.png", 1920, 960);
    dimensions("$dir/large-thumb.png", 640, 320);
    $thumb = imagecreatefrompng("$dir/large-thumb.png");
    check((imagecolorat($thumb, 0, 0) >> 24) === 127, 'Transparency lost');
    imagedestroy($thumb);
    imagedestroy($large);

    $small = imagecreatetruecolor(80, 40);
    imagefill($small, 0, 0, imagecolorallocate($small, 230, 40, 10));
    foreach (['jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'png', 'avif' => 'webp'] as $input => $output) {
        check(Images::save(encoded($small, $input), "$dir/small-$input") === $output, 'Format failed: ' . $input);
        dimensions("$dir/small-$input.$output", 80, 40);
        dimensions("$dir/small-$input-thumb.$output", 80, 40);
    }
    // Four distinct corners verify all mirrored/rotated EXIF orientations.
    imagefilledrectangle($small, 40, 0, 79, 19, imagecolorallocate($small, 10, 230, 40));
    imagefilledrectangle($small, 0, 20, 39, 39, imagecolorallocate($small, 10, 40, 230));
    imagefilledrectangle($small, 40, 20, 79, 39, imagecolorallocate($small, 230, 230, 10));
    $jpeg = encoded($small, 'jpeg');
    check(Images::save($png, "$dir/changed") === 'png', 'Initial thumbnail failed');
    check(Images::save($jpeg, "$dir/changed") === 'jpg', 'Replacement thumbnail failed');
    check(!is_file("$dir/changed-thumb.png"), 'Stale thumbnail retained after format change');
    $expectedTopLeft = [1 => 'red', 2 => 'green', 3 => 'yellow', 4 => 'blue', 5 => 'red', 6 => 'blue', 7 => 'yellow', 8 => 'green'];
    foreach ($expectedTopLeft as $orientation => $color) {
        check(Images::save(withOrientation($jpeg, $orientation), "$dir/oriented-$orientation") === 'jpg', 'EXIF processing failed');
        dimensions("$dir/oriented-$orientation.jpg", $orientation >= 5 ? 40 : 80, $orientation >= 5 ? 80 : 40);
        $image = imagecreatefromjpeg("$dir/oriented-$orientation.jpg");
        $rgb = imagecolorsforindex($image, imagecolorat($image, 5, 5));
        $actual = $rgb['red'] > 150 ? ($rgb['green'] > 150 ? 'yellow' : 'red') : ($rgb['green'] > 150 ? 'green' : 'blue');
        check($actual === $color, 'Incorrect EXIF transform ' . $orientation);
        check(strpos(file_get_contents("$dir/oriented-$orientation.jpg"), "Exif\0\0") === false, 'EXIF not stripped');
        imagedestroy($image);
    }
    imagedestroy($small);
    $tall = imagecreatetruecolor(1000, 6000);
    check(Images::save(encoded($tall, 'png'), "$dir/tall") === 'png', 'Tall image failed');
    $size = getimagesize("$dir/tall.png");
    check($size[0] * $size[1] <= 4000000 && abs($size[1] / $size[0] - 6) < .02, 'Tall image pixel/aspect bound failed');
    imagedestroy($tall);

    check(Images::save('not an image', "$dir/bad") === false, 'Malformed image accepted');
    $hugeHeader = substr_replace($png, pack('NN', 100000, 100000), 16, 8);
    check(Images::save($hugeHeader, "$dir/huge") === false, 'Oversized dimensions accepted');
    check(Images::save(str_repeat('x', Images::MAX_BYTES + 1), "$dir/bytes") === false, 'Oversized input accepted');
    check(Images::readResponse(new Response(200, ['Content-Length' => Images::MAX_BYTES + 1], 'x')) === false, 'Oversized response accepted');
    check(Images::readResponse(new Response(200, [], str_repeat('x', Images::MAX_BYTES + 1))) === false, 'Undeclared oversized response accepted');
    foreach ([65535, 65536, 65537, 131072, Images::MAX_BYTES] as $length) {
        $body = str_repeat('x', $length);
        check(Images::readResponse(new Response(200, [], $body)) === $body, 'Clean EOF rejected at ' . $length);
    }
    check(glob("$dir/.image-*") === [], 'Temporary images leaked');
    check(!file_exists("$dir/bad.png") && !file_exists("$dir/huge.png"), 'Failed image published');

    $client = new class($png) implements \Psr\Http\Client\ClientInterface {
        public $requests = 0;
        private $bytes;
        public function __construct($bytes) { $this->bytes = $bytes; }
        public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
            ++$this->requests;
            // Deliberately inaccurate header: output must follow detected bytes.
            return new Response(200, ['Content-Type' => 'image/jpeg'], $this->bytes);
        }
    };
    $download = new DownloadImages($client, "$dir/library", '', new \Psr\Log\NullLogger());
    $url = 'https://example.org/image.jpg';
    $article = $download->processSingleImage(1, $url, 'https://example.org/');
    $preview = $download->processSingleImage(1, $url, 'https://example.org/', null, true);
    check(str_ends_with($article, '.png') && str_ends_with($preview, '-thumb.png'), 'Separate article/preview URLs missing');
    check($client->requests === 1, 'Preview downloaded an image already processed for the article');
    check($download->processSingleImage(1, $article, 'https://example.org/') === $article, 'Local article not reused');
    check($download->processSingleImage(1, $article, 'https://example.org/', null, true) === $preview, 'Local article thumbnail not reused');
    check($download->processSingleImage(1, $preview, 'https://example.org/', null, true) === $preview, 'Local thumbnail not reused');
    check($client->requests === 1, 'Replay fetched already-local images');
    dimensions(str_replace('/assets/images', "$dir/library", $article), 1920, 960);
    dimensions(str_replace('/assets/images', "$dir/library", $preview), 640, 320);
    $avifImage = imagecreatetruecolor(1000, 500);
    $avif = encoded($avifImage, 'avif');
    imagedestroy($avifImage);
    $avifClient = new class($avif) implements \Psr\Http\Client\ClientInterface {
        public function __construct(private $bytes) {}
        public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
            return new Response(200, ['Content-Type' => 'image/avif'], $this->bytes);
        }
    };
    $avifDownload = new DownloadImages($avifClient, "$dir/avif-library", '', new \Psr\Log\NullLogger());
    $avifUrl = $avifDownload->processSingleImage(2, 'https://example.org/image.avif', 'https://example.org/');
    check(is_string($avifUrl) && str_ends_with($avifUrl, '.webp'), 'AVIF download did not become WebP');
    dimensions(str_replace('/assets/images', "$dir/avif-library", $avifUrl), 1000, 500);
    $download->removeImages(1);
    check(!is_file(str_replace('/assets/images', "$dir/library", $preview)), 'Thumbnail not removed with entry');
    echo "PASS: resize bounds, thumbnails, no upscaling, transparency, formats, all EXIF orientations, input limits, atomic cleanup, preview reuse and deletion.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($dir);
}
