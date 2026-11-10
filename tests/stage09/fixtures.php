<?php

/**
 * Etap 09 — syntetyczne pliki testowe w tests/stage09/fixtures. Każda utworzona ścieżka trafia do manifestu
 * /tmp/fmi-stage09-files.json, a sprzątanie usuwa wyłącznie pliki z tej listy.
 */

$dir = __DIR__ . '/fixtures';
$created = [];

$png = function (string $name, int $width, int $height, int $red) use ($dir, &$created) {
	$image = imagecreatetruecolor($width, $height);
	imagefill($image, 0, 0, imagecolorallocate($image, $red, 90, 140));
	imagepng($image, "{$dir}/{$name}");
	imagedestroy($image);
	$created[] = "{$dir}/{$name}";
};
$raw = function (string $name, string $content) use ($dir, &$created) {
	file_put_contents("{$dir}/{$name}", $content);
	$created[] = "{$dir}/{$name}";
};

$png('ok.png', 640, 480, 10);
$png('second.png', 640, 480, 60);
$png('third.png', 640, 480, 110);
$png('fourth.png', 640, 480, 160);
$png('fifth.png', 640, 480, 210);
$png('sixth.png', 640, 480, 240);
$png('small.png', 16, 16, 30);

$image = imagecreatetruecolor(300, 200);
imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
imagejpeg($image, "{$dir}/ok.jpg");
imagewebp($image, "{$dir}/ok.webp");
imagegif($image, "{$dir}/ok.gif");
imagebmp($image, "{$dir}/unsupported.bmp");
imagedestroy($image);
array_push($created, "{$dir}/ok.jpg", "{$dir}/ok.webp", "{$dir}/ok.gif", "{$dir}/unsupported.bmp");

$raw('php-as.jpg', "<?php\necho 'kod';\n");
$raw('script.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
$raw('note.txt', "Zwykły tekst\n");
$raw('broken.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64));
$raw('oversized.png', "\x89PNG\r\n\x1a\n" . str_repeat('x', (int) config('fillmein.assets.max_size_kb') * 1024 + 1024));

// Sam nagłówek PNG deklarujący ogromny obraz: bomba pikselowa bez kosztu pamięci w teście.
$header = "\0\0\0\rIHDR" . pack('NN', 11000, 11000) . "\x08\x02\0\0\0";
$raw('bomb.png', "\x89PNG\r\n\x1a\n" . $header . pack('N', crc32(substr($header, 4))) . str_repeat("\0", 32));

$path = '/tmp/fmi-stage09-files.json';
$known = json_decode((string) @file_get_contents($path), true) ?: [];
touch($path);
chmod($path, 0600);
file_put_contents($path, json_encode(array_values(array_unique([...$known, ...$created]))));

fwrite(STDOUT, 'Pliki testowe: ' . count($created) . "\n");
