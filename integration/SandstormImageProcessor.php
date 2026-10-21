<?php
namespace Wallabag\CoreBundle\Helper;

/** GD processing for newly downloaded images; limits reduce resource exhaustion, not decoder flaws. */
final class SandstormImageProcessor
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MAX_PIXELS = 12000000;
    public const MAX_DIMENSION = 16384;
    private const ARTICLE_PIXELS = 4000000;

    public static function readResponse($response)
    {
        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) return false;
        $body = $response->getBody();
        if ($body->isSeekable()) $body->rewind();
        $bytes = '';
        while (!$body->eof()) {
            $chunk = $body->read(min(65536, self::MAX_BYTES + 1 - strlen($bytes)));
            if ($chunk === '') {
                if ($body->eof()) break;
                return false; // An empty read without EOF must not spin forever.
            }
            if (strlen($bytes) + strlen($chunk) > self::MAX_BYTES) return false;
            $bytes .= $chunk;
        }
        return $bytes;
    }

    /** Write an article image and a separate thumbnail; return the canonical extension or false. */
    public static function save(string $bytes, string $basePath)
    {
        $source = null;
        $temporary = [];
        try {
            if (strlen($bytes) > self::MAX_BYTES) return false;
            $info = @getimagesizefromstring($bytes);
            if (!$info || $info[0] < 1 || $info[1] < 1 ||
                $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION ||
                $info[0] * $info[1] > self::MAX_PIXELS) return false;
            // Detect bytes, not HTTP headers. GIFs become a static PNG (GD already lost animation).
            $formats = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_AVIF => 'webp'];
            if (!isset($formats[$info[2]])) return false;
            $extension = $formats[$info[2]];
            // Account conservatively for decoding, EXIF rotation and resampling buffers.
            // System GD may allocate outside PHP's memory accounting, so also cap pixels above.
            $limit = ini_parse_quantity(ini_get('memory_limit'));
            $estimate = $info[0] * $info[1] * 12 + self::ARTICLE_PIXELS * 4 + strlen($bytes) * 2;
            if ($limit > 0 && memory_get_usage(true) + $estimate + 8 * 1024 * 1024 > $limit) return false;
            $orientation = $info[2] === IMAGETYPE_JPEG ? self::orientation($bytes) : 1;
            $source = @imagecreatefromstring($bytes);
            if (!$source) return false;
            imagepalettetotruecolor($source);
            imagealphablending($source, false);
            imagesavealpha($source, true);
            // Mirrored EXIF orientations are applied before rotation.
            if (in_array($orientation, [2, 5, 7], true)) imageflip($source, IMG_FLIP_HORIZONTAL);
            if ($orientation === 4) imageflip($source, IMG_FLIP_VERTICAL);
            $angle = [3 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90][$orientation] ?? 0;
            if ($angle) {
                $rotated = imagerotate($source, $angle, 0);
                if (!$rotated) return false;
                imagedestroy($source);
                $source = $rotated;
            }
            foreach (['' => [1920, 8192, self::ARTICLE_PIXELS], '-thumb' => [640, 640, 640 * 640]] as $suffix => $bounds) {
                $path = $basePath . $suffix . '.' . $extension;
                $tmp = tempnam(dirname($path), '.image-');
                if ($tmp === false) return false;
                $temporary[$path] = $tmp;
                if (!self::encode($source, $bounds, $extension, $tmp)) return false;
                clearstatcache(true, $tmp);
                if (filesize($tmp) < 1 || filesize($tmp) > self::MAX_BYTES) return false;
            }
            foreach ($temporary as $path => $tmp) {
                if (!rename($tmp, $path)) return false;
            }
            // A website can change format at the same URL; don't reuse an older thumbnail.
            foreach (['jpg', 'png', 'webp'] as $other) {
                $old = $basePath . '-thumb.' . $other;
                if ($other !== $extension && is_file($old)) unlink($old);
            }
            return $extension;
        } catch (\Throwable $e) {
            return false;
        } finally {
            if ($source instanceof \GdImage) imagedestroy($source);
            foreach ($temporary as $tmp) if (is_file($tmp)) unlink($tmp);
        }
    }

    private static function orientation(string $bytes): int
    {
        if (!function_exists('exif_read_data')) return 1;
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if (!$stream) return 1;
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes)) return 1;
            rewind($stream);
            $exif = @exif_read_data($stream, 'IFD0', true, false);
            return (int) ($exif['IFD0']['Orientation'] ?? 1);
        } finally {
            fclose($stream);
        }
    }

    private static function encode(\GdImage $source, array $bounds, string $extension, string $path): bool
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $bounds[0] / $width, $bounds[1] / $height, sqrt($bounds[2] / ($width * $height)));
        $targetWidth = max(1, (int) floor($width * $scale));
        $targetHeight = max(1, (int) floor($height * $scale));
        $target = $source;
        try {
            if ($targetWidth !== $width || $targetHeight !== $height) {
                $target = imagecreatetruecolor($targetWidth, $targetHeight);
                if (!$target) return false;
                imagealphablending($target, false);
                imagesavealpha($target, true);
                if (!imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) return false;
            }
            switch ($extension) {
                case 'jpg': return imagejpeg($target, $path, 80);
                case 'webp': return imagewebp($target, $path, 80);
                default: return imagepng($target, $path, 6);
            }
        } finally {
            if ($target instanceof \GdImage && $target !== $source) imagedestroy($target);
        }
    }
}
