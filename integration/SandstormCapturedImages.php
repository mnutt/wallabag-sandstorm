<?php
namespace Wallabag\CoreBundle\Helper;

/** Embedded captures only: this class never fetches URLs or reads caller-supplied paths. */
final class SandstormCapturedImages
{
    public const MAX_IMAGES = 32;
    public const MAX_TOTAL_BYTES = 12 * 1024 * 1024;
    public const MAX_IMAGE_BYTES = 3 * 1024 * 1024;

    public static function prepare(string $html): array
    {
        $images = [];
        $total = 0;
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT)) return [$html, []];
            $prefix = 'https://sandstorm-capture.invalid/' . bin2hex(random_bytes(16)) . '/';
            // A picture source can override its img even after src is replaced.
            foreach (iterator_to_array($dom->getElementsByTagName('source')) as $source) {
                $source->parentNode->removeChild($source);
            }
            foreach ($dom->getElementsByTagName('img') as $img) {
                $src = $img->getAttribute('src');
                $img->removeAttribute('data-sandstorm-original-src');
                // Captures may display only supplied image bytes, never a remote fallback.
                $img->removeAttribute('srcset');
                $img->removeAttribute('src');
                if (stripos($src, 'data:') !== 0) continue;
                if (strlen($src) > self::MAX_IMAGE_BYTES * 4 / 3 + 128 || count($images) >= self::MAX_IMAGES) continue;
                if (!preg_match('~^data:image/(png|jpeg|jpg|gif|webp|avif|svg\+xml);base64,([a-zA-Z0-9+/=\r\n]+)$~D', $src, $match)) continue;
                $bytes = base64_decode($match[2], true);
                if ($bytes === false || strlen($bytes) > self::MAX_IMAGE_BYTES || $total + strlen($bytes) > self::MAX_TOTAL_BYTES) continue;
                $hash = hash('sha256', $bytes);
                $placeholder = $prefix . $hash . '.png';
                if (!isset($images[$placeholder])) {
                    $images[$placeholder] = ['bytes' => $bytes, 'svg' => $match[1] === 'svg+xml'];
                    $total += strlen($bytes);
                }
                // Ordinary HTTPS placeholder survives upstream extraction/sanitization.
                $img->setAttribute('src', $placeholder);
            }
            return [$dom->saveHTML(), $images];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function save($entry, DownloadImages $download, array $images): void
    {
        // Remote previews must not escape through the article list or API.
        if (!isset($images[$entry->getPreviewPicture()]) && !preg_match('~^/assets/images/[a-zA-Z0-9/_.-]+$~D', (string) $entry->getPreviewPicture())) {
            $entry->setPreviewPicture(null);
        }
        if (!$images) return;
        require_once '/opt/app/integration/SandstormImageProcessor.php';
        $relative = $download->getRelativePath($entry->getId());
        $folder = $download->getBaseFolder() . '/' . $relative;
        $html = $entry->getContent();
        $preview = $entry->getPreviewPicture();
        $selected = null;
        foreach ($images as $placeholder => $image) {
            $bytes = $image['bytes'];
            if (strpos($html, $placeholder) === false && $preview !== $placeholder) continue;
            $hash = 'capture-' . hash('sha256', $bytes);
            $extension = !empty($image['svg'])
                ? self::saveSvg($bytes, $folder . '/' . $hash)
                : SandstormImageProcessor::save($bytes, $folder . '/' . $hash);
            $url = '/assets/images/' . $relative . '/' . $hash;
            $replacement = $extension ? $url . '.' . $extension : 'data:,';
            $html = str_replace($placeholder, htmlspecialchars($replacement, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
            if ($extension && ($selected === null || $preview === $placeholder)) {
                $selected = $url . ($extension === 'svg' ? '.svg' : '-thumb.' . $extension);
            }
        }
        $entry->setContent($html);
        if ($selected !== null) $entry->setPreviewPicture($selected);
        elseif (isset($images[$preview])) $entry->setPreviewPicture(null);
    }

    /** Store sanitized vectors; nginx forces attachment disposition for direct navigation. */
    private static function saveSvg(string $bytes, string $basePath)
    {
        $previous = libxml_use_internal_errors(true);
        $temporary = null;
        try {
            // No DTD loading or entity substitution. Reject document types altogether,
            // and serialize only the SVG root so processing instructions are discarded.
            $dom = new \DOMDocument();
            if (!$dom->loadXML($bytes, LIBXML_NONET | LIBXML_COMPACT) || $dom->doctype ||
                !$dom->documentElement || $dom->documentElement->localName !== 'svg' ||
                $dom->documentElement->namespaceURI !== 'http://www.w3.org/2000/svg') return false;
            $sanitizer = new \enshrined\svgSanitize\Sanitizer();
            $sanitizer->removeRemoteReferences(true);
            $clean = $sanitizer->sanitize($dom->saveXML($dom->documentElement));
            if (!$clean || strlen($clean) > self::MAX_IMAGE_BYTES) return false;
            if (!$dom->loadXML($clean, LIBXML_NONET | LIBXML_COMPACT)) return false;
            // The pinned sanitizer's remote-reference option only handles some
            // CSS url() forms. Restrict href/xlink:href to in-document references.
            foreach ($dom->getElementsByTagName('*') as $element) {
                foreach (iterator_to_array($element->attributes) as $attribute) {
                    if (in_array($attribute->localName, ['href', 'src'], true) &&
                        !preg_match('/^#[a-zA-Z0-9_.:-]+$/D', $attribute->value)) {
                        $element->removeAttributeNode($attribute);
                    }
                }
            }
            $clean = $dom->saveXML($dom->documentElement);
            $folder = dirname($basePath);
            if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) return false;
            $temporary = tempnam($folder, '.svg-');
            if ($temporary === false || file_put_contents($temporary, $clean) !== strlen($clean) ||
                !rename($temporary, $basePath . '.svg')) return false;
            return 'svg';
        } catch (\Throwable $e) {
            return false;
        } finally {
            if ($temporary && is_file($temporary)) unlink($temporary);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

}
