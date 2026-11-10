<?php

/**
 * SiteMatcher bez bazy danych: strony, sekcje ↔ bloki (wyrównanie sekwencji), pola ↔ pola ACF,
 * grupy przezroczyste, zagnieżdżone repeatery, powtórzony blok, ręczne dopasowania.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Services\SiteMatcher;
use App\Support\SiteSchemaIndex;

$schema = new SiteSchemaIndex(require __DIR__ . '/fixture.php');
$matcher = new SiteMatcher;

$id = 0;
$node = function (string $label, string $type, array $children = [], ?string $text = null) use (&$id) {
	return ['id' => ++$id, 'label' => $label, 'key' => strtolower(str_replace(' ', '_', $label)), 'type' => $type, 'text' => $text, 'children' => $children];
};
$section = function (string $name, array $fields, bool $hidden = false) use (&$id) {
	return ['id' => ++$id, 'name' => $name, 'hidden' => $hidden, 'fields' => $fields];
};

/** Sekcje typowej strony głównej z Figmy (nazwy warstw, nie bloków). */
$hero = fn () => $section('Frame 347', [
	$node('Zachowaj ciepło i oszczędzaj energię', 'text'),
	$node('Opis', 'textarea'),
	$node('Przycisk', 'link'),
	$node('Zdjęcie', 'image'),
]);
$slider = fn () => $section('Frame 12', [
	$node('Grupa nagłówka', 'group', [$node('Tytuł', 'text'), $node('Opis', 'textarea')]),
	$node('Karty', 'repeater', [$node('Ikona', 'image'), $node('Tytuł', 'text')]),
]);
$cta = fn () => $section('Frame 88', [
	$node('Treść', 'group', [$node('Nagłówek', 'text'), $node('Przycisk', 'link')]),
]);
$text = fn () => $section('Frame 90', [$node('Treść', 'wysiwyg')]);
$page = function (array $sections, string $slug = 'strona-glowna', string $name = 'Strona główna') use (&$id) {
	return ['id' => ++$id, 'name' => $name, 'slug' => $slug, 'post_type' => 'page', 'sections' => $sections];
};
$blocks = fn (array $result, array $page) => array_map(fn ($s) => [$result['sections'][$s['id']]['block'], $result['sections'][$s['id']]['position']], $page['sections']);

Harness::test('Indeks schematu: ścieżki z grupami i zagnieżdżonymi repeaterami', function () use ($schema) {
	Harness::same('g_top.header', $schema->field('acf/hero', 'field_hero_g_top_header')['path'], 'pole w grupie');
	Harness::same('r_cards.*.title', $schema->field('acf/slider', 'field_slider_r_cards_title')['path'], 'pole w repeaterze');
	Harness::same('r_list.*.r_cards.*.icon', $schema->field('acf/features', 'field_features_r_list_r_cards_icon')['path'], 'repeater w repeaterze');
	Harness::same('field_features_r_list_r_cards', $schema->field('acf/features', 'field_features_r_list_r_cards_icon')['scope'], 'zakres = najbliższy repeater');
	Harness::same(['field_faq_layouts'], array_keys($schema->fields('acf/faq')), 'wnętrze flexible content pominięte');
	Harness::same([0, 1, 3, 4, 5], array_column($schema->pageBlocks('12'), 'position'), 'tylko bloki ACF strony');
});

Harness::test('Normalizacja i podobieństwo nazw', function () {
	Harness::same('zachowaj cieplo i oszczedzaj energie', SiteMatcher::normalize('Zachowaj ciepło i oszczędzaj energię!'), 'normalizacja');
	Harness::same(1.0, SiteMatcher::similarity('Strona Główna', 'strona-glowna'), 'te same po normalizacji');
	Harness::assert(SiteMatcher::similarity('Karty', 'Kart') > 0.7, 'podobne');
	Harness::assert(SiteMatcher::similarity('Frame 347', 'slider') < 0.2, 'niepodobne');
});

Harness::test('Pełna strona: sekcje ↔ bloki w kolejności, powtórzony blok, pewność', function () use ($matcher, $schema, $page, $hero, $slider, $cta, $text, $blocks) {
	$p = $page([$hero(), $slider(), $cta(), $cta(), $text()]);
	$result = $matcher->match(['pages' => [$p]], $schema);

	Harness::same(['target' => '12', 'confidence' => 1.0], $result['pages'][$p['id']], 'strona po slugu');
	Harness::same([['acf/hero', 0], ['acf/slider', 1], ['acf/cta', 3], ['acf/cta', 4], ['acf/text', 5]], $blocks($result, $p), 'bloki');

	foreach ($p['sections'] as $s) {
		Harness::assert($result['sections'][$s['id']]['confidence'] >= SiteMatcher::GREEN, 'sekcja „' . $s['name'] . '” zielona: ' . $result['sections'][$s['id']]['confidence']);
	}
});

Harness::test('Dodatkowa sekcja w Figmie nie przesuwa pozostałych par', function () use ($matcher, $schema, $page, $hero, $slider, $cta, $text, $section, $node, $blocks) {
	$extra = $section('Frame 999', [$node('Galeria', 'gallery'), $node('Podpis', 'text')]);
	$p = $page([$hero(), $extra, $slider(), $cta(), $cta(), $text()]);
	$result = $matcher->match(['pages' => [$p]], $schema);
	$got = $blocks($result, $p);

	Harness::same([['acf/hero', 0], ['acf/slider', 1], ['acf/cta', 3], ['acf/cta', 4], ['acf/text', 5]], [$got[0], ...array_slice($got, 2)], 'pary bez przesunięcia');
	Harness::same(null, $got[1][1], 'dodatkowa sekcja nie zajmuje bloku strony');
});

Harness::test('Brakująca sekcja w Figmie zostawia blok wolny', function () use ($matcher, $schema, $page, $hero, $cta, $text, $blocks) {
	$p = $page([$hero(), $cta(), $cta(), $text()]);
	$result = $matcher->match(['pages' => [$p]], $schema);

	Harness::same([['acf/hero', 0], ['acf/cta', 3], ['acf/cta', 4], ['acf/text', 5]], $blocks($result, $p), 'slider pominięty');
});

Harness::test('Grupa w Figmie ↔ płaskie pola w ACF i odwrotnie', function () use ($matcher, $schema, $page, $hero, $cta) {
	$h = $hero();
	$c = $cta();
	$p = $page([$h, $c]);
	$result = $matcher->match(['pages' => [$p]], $schema);
	$key = fn ($node) => $result['fields'][$node['id']]['key'];

	// Hero: płaskie pola w Figmie, w ACF tytuł i opis są w grupie g_top.
	Harness::same(['field_hero_g_top_header', 'field_hero_g_top_text', 'field_hero_button', 'field_hero_image'], array_map($key, $h['fields']), 'hero przez grupę ACF');

	// CTA: grupa „Treść” w Figmie, w ACF pola leżą płasko — grupa przezroczysta.
	Harness::same(null, $key($c['fields'][0]), 'grupa bez odpowiednika');
	Harness::same(['field_cta_header', 'field_cta_button'], array_map($key, $c['fields'][0]['children']), 'pola z grupy');
});

Harness::test('Grupa ↔ grupa i repeater ↔ repeater w sliderze', function () use ($matcher, $schema, $page, $slider) {
	$s = $slider();
	$p = $page([$s]);
	$result = $matcher->match(['pages' => [$p]], $schema);
	$key = fn ($node) => $result['fields'][$node['id']]['key'];

	Harness::same('field_slider_g_top', $key($s['fields'][0]), 'grupa ↔ grupa');
	Harness::same(['field_slider_g_top_header', 'field_slider_g_top_text'], array_map($key, $s['fields'][0]['children']), 'pola grupy');
	Harness::same('field_slider_r_cards', $key($s['fields'][1]), 'repeater');
	Harness::same(['field_slider_r_cards_icon', 'field_slider_r_cards_title'], array_map($key, $s['fields'][1]['children']), 'dzieci repeatera w jego sub_fields');
});

Harness::test('Zagnieżdżony repeater na nowej stronie (blok z całego motywu)', function () use ($matcher, $schema, $page, $section, $node) {
	$inner = $node('Karty', 'repeater', [$node('Ikona', 'image'), $node('Tytuł', 'text')]);
	$outer = $node('Lista', 'repeater', [$node('Tytuł', 'text'), $inner]);
	$s = $section('Frame 5', [$node('Nagłówek', 'text'), $outer]);
	$p = $page([$s], 'nowa-strona', 'Nowa strona');
	$result = $matcher->match(['pages' => [$p]], $schema);
	$key = fn ($node) => $result['fields'][$node['id']]['key'];

	Harness::same(null, $result['pages'][$p['id']]['target'], 'nowa strona bez pary');
	Harness::same('acf/features', $result['sections'][$s['id']]['block'], 'blok o najbliższej strukturze');
	Harness::same(null, $result['sections'][$s['id']]['position'], 'nowy blok na stronie');
	Harness::same('field_features_r_list', $key($outer), 'repeater zewnętrzny');
	Harness::same('field_features_r_list_title', $key($outer['children'][0]), 'pole repeatera zewnętrznego');
	Harness::same('field_features_r_list_r_cards', $key($inner), 'repeater wewnętrzny');
	Harness::same(['field_features_r_list_r_cards_icon', 'field_features_r_list_r_cards_title'], array_map($key, $inner['children']), 'pola repeatera wewnętrznego');
});

Harness::test('Repeater nie jest spłaszczany: pola spoza repeatera nie trafiają do sub_fields', function () use ($matcher, $schema, $page, $section, $node) {
	// Repeater z Figmy ma pole tekstowe, ale nie ma pary repeatera — jego pole nie może wpaść w r_cards.*.
	$s = $section('Frame 6', [$node('Tytuł', 'text'), $node('Lista', 'repeater', [$node('Tekst', 'wysiwyg')])]);
	$result = $matcher->match(['pages' => [$page([$s], 'x', 'X')]], $schema);
	$block = $result['sections'][$s['id']]['block'];

	foreach ($s['fields'][1]['children'] as $child) {
		$key = $result['fields'][$child['id']]['key'];
		$field = $schema->field($block, $key);

		Harness::assert($key === null || $field['scope'] === $result['fields'][$s['fields'][1]['id']]['key'], 'dziecko repeatera tylko w zakresie jego pary');
	}

	$root = $schema->field($block, $result['fields'][$s['fields'][0]['id']]['key']);
	Harness::assert($root !== null && $root['scope'] === null, 'pole sekcji w korzeniu bloku');
});

Harness::test('Strona po podobieństwie tytułu i nowa strona', function () use ($matcher, $schema, $page, $text) {
	$about = $page([$text()], 'about', 'O firmie GreenQ');
	$new = $page([$text()], 'kontakt', 'Kontakt');
	$result = $matcher->match(['pages' => [$about, $new]], $schema);

	Harness::same('20', $result['pages'][$about['id']]['target'], 'tytuł ≥ 0.7');
	Harness::assert($result['pages'][$about['id']]['confidence'] < 1.0, 'pewność z podobieństwa');
	Harness::same(null, $result['pages'][$new['id']]['target'], 'brak pary — nowa strona');
	Harness::same([['acf/text', 0]], [[$result['sections'][$about['sections'][0]['id']]['block'], $result['sections'][$about['sections'][0]['id']]['position']]], 'sekcja na stronie z pary');
});

Harness::test('Ręczne dopasowania: nie są nadpisywane i blokują swoje cele', function () use ($matcher, $schema, $page, $hero, $cta, $text) {
	$h = $hero();
	$c1 = $cta();
	$c2 = $cta();
	$t = $text();
	$p = $page([$h, $c1, $c2, $t]);
	$fixed = [
		'sections' => [$c1['id'] => ['block' => 'acf/cta', 'position' => 4]],
		'fields' => [$h['fields'][0]['id'] => 'field_hero_g_top_text', $h['fields'][3]['id'] => null],
	];
	$result = $matcher->match(['pages' => [$p]], $schema, $fixed);

	Harness::assert(! isset($result['sections'][$c1['id']]), 'ręczna sekcja poza wynikiem automatu');
	Harness::same('acf/cta', $result['sections'][$c2['id']]['block'], 'druga sekcja CTA dalej na CTA');
	Harness::same(3, $result['sections'][$c2['id']]['position'], 'automat bierze wolny blok CTA');
	Harness::assert(! isset($result['fields'][$h['fields'][0]['id']]) && ! isset($result['fields'][$h['fields'][3]['id']]), 'ręczne pola poza wynikiem');
	Harness::assert($result['fields'][$h['fields'][1]['id']]['key'] !== 'field_hero_g_top_text', 'klucz zajęty ręcznie nie jest użyty drugi raz');
});

Harness::test('Każde pole ACF najwyżej raz w sekcji, typy niezgodne bez pary', function () use ($matcher, $schema, $page, $section, $node) {
	$s = $section('Frame 77', [$node('A', 'text'), $node('B', 'text'), $node('C', 'text'), $node('Galeria', 'gallery')]);
	$result = $matcher->match(['pages' => [$page([$s], 'x', 'X')]], $schema, ['sections' => [$s['id'] => ['block' => 'acf/cta', 'position' => null]]]);
	$keys = array_filter(array_map(fn ($n) => $result['fields'][$n['id']]['key'], $s['fields']));

	Harness::same(count($keys), count(array_unique($keys)), 'bez powtórzeń');
	Harness::same(null, $result['fields'][$s['fields'][3]['id']]['key'], 'galeria nie trafia na tekst ani link');
	Harness::same(null, SiteMatcher::compatibility('image', 'text'), 'image ↛ text');
	Harness::assert(SiteMatcher::compatibility('link', 'url') < SiteMatcher::compatibility('link', 'link'), 'link → url słabiej');
});

exit(Harness::summary('Schemat strony — algorytm dopasowania'));
