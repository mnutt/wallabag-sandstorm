<?php
// Run in the build VM after applying patches: php tests/code-blocks.php [article.html]
require '/opt/app/build/wallabag/vendor/autoload.php';
if (getenv('CODE_READABILITY_FILE')) require getenv('CODE_READABILITY_FILE');
// Allow isolated testing while a dev session still uses the previous build.
if (getenv('CODE_EXTRACTOR_FILE')) require getenv('CODE_EXTRACTOR_FILE');
if (getenv('CODE_GRABY_FILE')) require getenv('CODE_GRABY_FILE');
function codeText(string $html): array {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    $result = [];
    foreach ($doc->getElementsByTagName('pre') as $pre) $result[] = $pre->textContent;
    return $result;
}
function checkCode(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$expected = "const float VALUE = 8.0;\n\nvoid main() {\n  if (x < 2.0 && y > 1.0) {\n    // café <tag>\n  }\n}";
$lines = '';
foreach (explode("\n", $expected) as $i => $line) {
    $tokens = '';
    foreach (preg_split('/( +)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) as $token) {
        $tokens .= '<span class="token plain">' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $lines .= '<div class="token-line"><div data-testid="number-line">' . ($i + 1) . '</div><span>' . $tokens . '</span></div>';
}
$html = '<html><head><meta charset="UTF-8"><title>Code example</title></head><body><article>' . str_repeat('<p>Explaining a shader with enough readable text to retain this article during extraction.</p>', 12) .
    '<pre class="prism-code language-glsl">' . $lines . '</pre><pre>ordinary  code' . "\n  stays indented</pre></article></body></html>";
foreach (['fetch', 'capture'] as $mode) {
    $graby = new Graby\Graby();
    if ($mode === 'fetch') {
        $graby->setContentAsPrefetched($html);
        $output = $graby->fetchContent('https://example.org/article')['html'];
    } else $output = $graby->cleanupHtml($html, 'https://example.org/article');
    $blocks = codeText($output);
    checkCode(in_array($expected, $blocks, true), "$mode lost code whitespace, punctuation, or Unicode: " . json_encode($blocks));
    checkCode(in_array("ordinary  code\n  stays indented", $blocks, true), "$mode changed ordinary pre");
}
if (isset($argv[1])) {
    require_once '/opt/app/integration/SandstormCodeBlocks.php';
    $html = file_get_contents($argv[1]);
    $expectedBlocks = codeText(SandstormCodeBlocks::normalize($html));
    $graby = new Graby\Graby();
    $graby->setContentAsPrefetched($html);
    $result = $graby->fetchContent('https://blog.maximeheckel.com/posts/on-rendering-the-sky-sunsets-and-planets/');
    $actualBlocks = codeText($result['html']);
    checkCode(count($expectedBlocks) > 0, 'No source code blocks');
    checkCode(in_array($expectedBlocks[0], $actualBlocks, true), 'Reported first code block changed');
    checkCode(count($actualBlocks) >= 15, 'Too few article code blocks survived extraction');
    foreach ($actualBlocks as $i => $block) checkCode(in_array($block, $expectedBlocks, true), "Extracted article block $i differs from source");
    echo 'PASS: all ' . count($actualBlocks) . " extracted article code blocks match source text exactly\n";
}
echo "PASS: fetched and captured code, token spaces, indentation, blank lines, punctuation, Unicode, and ordinary pre\n";
