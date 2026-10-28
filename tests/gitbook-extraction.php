<?php
// Optional argv[1]: downloaded GitBook article, otherwise a synthetic fixture.
require '/opt/app/build/wallabag/vendor/autoload.php';
$builder = new Graby\SiteConfig\ConfigBuilder(['site_config' => ['/opt/app/integration']]);
$graby = new Graby\Graby(['error_message' => 'EXTRACTION_FAILED'], null, $builder);
$html = isset($argv[1]) ? file_get_contents($argv[1]) : '<html><head><title>Redis</title></head><body><nav>Sidebar</nav><main><h1>High Performance Redis</h1><p>Redis performance article content.</p><nav>Next article</nav></main></body></html>';
$graby->setContentAsPrefetched($html);
$result = $graby->fetchContent('https://agrat.gitbook.io/admin/redis/high-performance-redis');
if ($result['html'] === 'EXTRACTION_FAILED' || !str_contains($result['title'], 'High Performance Redis')) throw new RuntimeException('GitBook extraction failed');
if (isset($argv[1]) && !str_contains($result['html'], 'high-performance techniques')) throw new RuntimeException('Missing article text');
echo 'PASS: GitBook main article extracted (' . strlen($result['html']) . " bytes)\n";
