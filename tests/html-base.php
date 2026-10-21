<?php
// Run in the build VM after building: php tests/html-base.php
require '/opt/app/build/wallabag/vendor/autoload.php';
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
$url = 'https://example.org/article/';
$body = '<article><h1>Image URL regression</h1>' . str_repeat('<p>This article has enough readable content to survive normal article extraction.</p>', 12) .
    '<img src="_media/photo.avif"><a href="more">More</a></article>';
foreach (['/' => 'https://example.org/', '/photos/' => 'https://example.org/photos/', 'https://cdn.example.org/' => 'https://cdn.example.org/', 'javascript:bad' => $url] as $base => $expected) {
    $html = '<html><head><title>Image URL regression</title><base href="' . $base . '"><base href="https://ignored.invalid/"></head><body>' . $body . '</body></html>';
    $graby = new Graby\Graby();
    $clean = $graby->cleanupHtml($html, $url);
    check(str_contains($clean, $expected . '_media/photo.avif'), 'Browser content resolved wrong base: ' . $base);
    $graby->setContentAsPrefetched($html);
    $result = $graby->fetchContent($url);
    check(str_contains($result['html'], $expected . '_media/photo.avif'), 'URL import resolved wrong base: ' . $base);
    check(str_contains($result['html'], $expected . 'more'), 'Link resolved wrong base');
    check($result['url'] === $url, 'Article URL changed');
}
echo "PASS: fetched/browser HTML base URLs, relative and cross-origin bases, first-base semantics, unsafe schemes and unchanged article URL.\n";
