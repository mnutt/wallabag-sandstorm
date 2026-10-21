<?php
// Sample RGB values independently of timing to catch incorrect raw-pixel output.
$image = imagecreatefromstring(file_get_contents($argv[1]));
$width = imagesx($image); $height = imagesy($image);
$samples = [];
for ($i = 0; $i < 16; ++$i) {
    $offset = (int) floor(($i + .5) * $width * $height / 16);
    $rgb = imagecolorsforindex($image, imagecolorat($image, $offset % $width, intdiv($offset, $width)));
    $samples[] = [$rgb['red'], $rgb['green'], $rgb['blue'], 255 - (int) round($rgb['alpha'] * 255 / 127)];
}
echo json_encode($samples);
