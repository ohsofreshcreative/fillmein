<?php

/**
 * Rozbijanie dużych frame'ów na sekcje (FrameAnalyzer::split) na syntetycznym drzewie — bez API Figmy.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Integrations\Figma\FrameAnalyzer;

$id = 0;
$box = fn (float $y, float $w, float $h, float $x = 0) => ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h];
$text = function (string $name, string $characters, float $y) use (&$id, $box) {
	return ['id' => '1:' . ++$id, 'name' => $name, 'type' => 'TEXT', 'characters' => $characters, 'box' => $box($y, 600, 40, 300)];
};
$frame = function (string $name, array $children, array $b) use (&$id) {
	return ['id' => '2:' . ++$id, 'name' => $name, 'type' => 'FRAME', 'box' => $b, 'children' => $children];
};

/** Blok treści na pełną szerokość z nagłówkiem i opisem w węższym wrapperze. */
$block = fn (string $heading, float $y, float $h = 600) => $frame('content', [
	$frame('__wrapper', [$text('title', $heading, $y + 40), $text('desc', $heading . ' — opis bloku treści.', $y + 100)], $box($y, 1328, $h, 296)),
], $box($y, 1920, $h));

$analyze = function (array $document, array $override = []) {
	$config = array_merge((array) config('fillmein.figma.analysis'), $override);

	return (new FrameAnalyzer([], $config))->analyze($document);
};
$names = fn (array $analysis) => array_column($analysis['sections'], 'name');

Harness::test('Duży frame z blokami na pełną szerokość jest rozbijany, nazwy z nagłówków', function () use ($frame, $block, $box, $text, $analyze, $names) {
	$big = $frame('Frame 318', [$block('Akustyka', 600), $block('Termoizolacja', 1200), $block('Certyfikacja', 1800)], $box(600, 1920, 1800));
	$doc = $frame('solutions', [$frame('Frame 315', [$text('h1', 'Płyta DKW', 100)], $box(0, 1920, 600)), $big], $box(0, 1920, 2400));
	$analysis = $analyze($doc);

	Harness::same(['Frame 315', 'Akustyka', 'Termoizolacja', 'Certyfikacja'], $names($analysis), 'sekcje');
	Harness::assert(in_array($big['id'], $analysis['wrappers'], true), 'rozbity frame w wrappers');
	Harness::same(['Frame 315', 'Frame 318'], $names($analyze($doc, ['split_large_frames' => false])), 'wyłączone w konfiguracji');
});

Harness::test('Pasek zakładek + jeden wysoki kontener z sekcjami — rozbijanie dwupoziomowe', function () use ($frame, $block, $box, $text, $analyze, $names) {
	$tabs = $frame('__btns', [$text('a', 'Opis', 610), $text('b', 'Parametry', 610)], $box(600, 1280, 52, 320));
	$inner = $frame('Frame 316', [$block('Opis rozwiązania', 700, 850), $block('Parametry techniczne', 1550, 850)], $box(700, 1920, 1700));
	$outer = $frame('Frame 317', [$tabs, $inner], $box(600, 1920, 1800));
	$doc = $frame('solutions', [$frame('Frame 315', [$text('h1', 'Płyta', 100)], $box(0, 1920, 600)), $outer], $box(0, 1920, 2400));

	Harness::same(['Frame 315', 'btns', 'Opis rozwiązania', 'Parametry techniczne'], $names($analyze($doc)), 'sekcje');
});

Harness::test('Lista w kontenerze treści i zwykłe sekcje nie są rozbijane', function () use ($frame, $box, $text, $analyze, $names) {
	$row = fn (string $name, float $y) => $frame('__products', [$text('t', $name, $y), $frame('__cards', [$text('c', 'Karta', $y + 60)], $box($y + 50, 1280, 500, 320))], $box($y, 1280, 576, 320));
	$products = $frame('products', [$frame('__wrapper', [$row('Standard', 700), $row('Grafit', 1300), $row('Wełna', 1900)], $box(600, 1328, 1900, 296))], $box(600, 1920, 2004));
	$hero = $frame('Frame 347', [$text('h1', 'Zachowaj ciepło', 100), $text('p', 'Opis', 160)], $box(0, 1920, 600));
	$doc = $frame('homepage', [$hero, $products], $box(0, 1920, 2604));

	Harness::same(['Frame 347', 'products'], $names($analyze($doc)), 'sekcje bez zmian');
});

Harness::test('Głębokość drzewa z Figmy wystarcza na głęboko zagnieżdżone formularze i menu', function () {
	Harness::assert((int) config('fillmein.figma.node_depth') >= 16, 'node_depth ≥ 16');
	Harness::assert((int) config('fillmein.figma.max_nodes') >= 10000, 'max_nodes ≥ 10000');
});

exit(Harness::summary('Figma — rozbijanie dużych frame\'ów'));
