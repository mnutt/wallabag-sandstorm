<?php
// Run in the build VM after building; optional cached article path as argv[1].
require '/opt/app/build/wallabag/vendor/autoload.php';
if (getenv('INLINE_READABILITY_FILE')) require getenv('INLINE_READABILITY_FILE');
$sentence = 'The x-axis, vUv.x maps to the azimuth, i.e., left-to-right directions from [-PI, PI].';
$body = '<div>The x-axis, vUv.x maps to the <strong>azimuth</strong>, i.e., left-to-right directions from <code>[-PI, PI]</code>.</div>';
$literal = '<div>Compare 1 &lt; 2 &amp; 3 &gt; 2 with <strong>literal &lt;tag&gt;</strong> and <code>x &amp;&amp; y</code>.</div>';
$html = '<html><head><meta charset="utf-8"><title>Inline spacing regression</title></head><body><article>' . str_repeat('<p>This explanatory article has enough readable text to survive content extraction and demonstrate inline formatting.</p>', 12) . $body . $literal . '<div>un<strong>broken</strong>, <em>words</em>!</div></article></body></html>';
function plain(string $html): string {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    return preg_replace('/\s+/u', ' ', $doc->textContent);
}
foreach (['fetch', 'capture'] as $mode) {
    $graby = new Graby\Graby();
    if ($mode === 'fetch') {
        $graby->setContentAsPrefetched($html);
        $clean = $graby->fetchContent('https://example.org/')['html'];
    } else $clean = $graby->cleanupHtml($html, 'https://example.org/');
    foreach ([$sentence, 'Compare 1 < 2 & 3 > 2 with literal <tag> and x && y.', 'unbroken, words!'] as $expected) {
        if (!str_contains(plain($clean), $expected)) throw new RuntimeException("$mode lost inline text: $expected\n$clean");
    }
}
if (isset($argv[1])) {
    $graby = new Graby\Graby();
    $graby->setContentAsPrefetched(file_get_contents($argv[1]));
    $clean = $graby->fetchContent('https://blog.maximeheckel.com/posts/on-rendering-the-sky-sunsets-and-planets/')['html'];
    if (!str_contains(plain($clean), $sentence)) throw new RuntimeException('Article sentence still loses spacing');
}
echo "PASS: inline boundary spaces, adjacent tokens, punctuation and literal markup in fetched/captured HTML\n";
