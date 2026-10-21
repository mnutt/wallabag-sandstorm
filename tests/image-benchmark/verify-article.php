<?php
require '/opt/app/build/wallabag/vendor/autoload.php';
require '/opt/app/integration/SandstormImageProcessor.php';
$g=new Graby\Graby();
$g->setContentAsPrefetched(file_get_contents('/opt/app/.sandstorm/caches/unsung-article.html'));
$r=$g->fetchContent('https://unsung.aresluna.org/good-type-against-all-odds/');
$urls=Wallabag\CoreBundle\Helper\DownloadImages::extractImagesUrlsFromHtml($r['html']);
if (count($urls) !== 12) throw new Exception('Images lost during extraction');
foreach($urls as $url) {
    if (!str_starts_with($url, 'https://unsung.aresluna.org/_media/')) throw new Exception('Incorrect image URL');
}
$path='/tmp/wallabag-image-bench/article';
$result=Wallabag\CoreBundle\Helper\SandstormImageProcessor::save(file_get_contents($path.'.avif'), $path.'-processed');
if ($result !== 'webp') throw new Exception('Real AVIF image failed');
echo count($urls)." real article images resolved correctly; actual AVIF resized with thumbnail.\n";
