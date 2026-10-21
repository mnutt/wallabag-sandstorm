<?php
$bytes = file_get_contents($argv[1]);
$iterations = (int) ($argv[2] ?? 30);
$start = hrtime(true);
$image = imagecreatefromstring($bytes);
if (!$image) throw new RuntimeException('Decode failed');
$first = (hrtime(true) - $start) / 1e6;
$width = imagesx($image); $height = imagesy($image); imagedestroy($image);
for ($i = 0; $i < 5; ++$i) { $image = imagecreatefromstring($bytes); imagedestroy($image); }
$times = [];
for ($i = 0; $i < $iterations; ++$i) {
    $start = hrtime(true);
    $image = imagecreatefromstring($bytes);
    $times[] = (hrtime(true) - $start) / 1e6;
    if (imagesx($image) !== $width || imagesy($image) !== $height) throw new RuntimeException('Bad dimensions');
    imagedestroy($image);
}
sort($times);
echo json_encode(['decoder'=>'GD','width'=>$width,'height'=>$height,'bytes'=>strlen($bytes),'first_ms'=>$first,'median_ms'=>$times[intdiv(count($times),2)],'p95_ms'=>$times[(int) floor(count($times)*.95)],'rss_kib'=>getrusage()['ru_maxrss']]) . "\n";
