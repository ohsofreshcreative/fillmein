<?php

/**
 * Etap 12 — stan po imporcie czytany przez ACF (`get_field`), wszystkie typy pól, hierarchia, media z ALT, grupy.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Field;
use App\Models\Section;
use App\Services\Import\ImportEnvironment;
use App\Services\Import\WpImporter;

global $wpdb;

$state = json_decode((string) file_get_contents('/tmp/fmi-stage12-state.json'), true);
$ids = json_decode((string) file_get_contents('/tmp/fmi-stage11-ids.json'), true);
$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$document = json_decode((string) file_get_contents($state['json']), true);

app(ImportEnvironment::class)->loadAcf();

$pageId = fn (string $uuid) => (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", WpImporter::META_KEY, 'page:' . $uuid));
$homeUuid = \App\Models\Page::query()->findOrFail((int) $manifest['pages']['a'])->uuid;
$childUuid = \App\Models\Page::query()->findOrFail($ids['pages']['child'])->uuid;
$home = $pageId($homeUuid);
$child = $pageId($childUuid);
$fields = collect($document['fields'])->keyBy('id');
$name = fn (string $alias) => $fields[Field::query()->findOrFail($ids['fields'][$alias])->uuid]['acf']['name'];
$sectionUuid = fn (string $alias) => Section::query()->findOrFail($ids['sections'][$alias])->uuid;
$hero = get_field(WpImporter::sectionFieldKey($sectionUuid('hero')), $home);
$values = get_field(WpImporter::sectionFieldKey($sectionUuid('values')), $home);
$assets = collect($document['assets'])->keyBy('id');

Harness::test('Strony: hierarchia, szkic, znaczniki, tytuł ze znakiem &', function () use ($home, $child, $state, $document) {
	global $wpdb;

	Harness::assert($home > 0 && $child > 0, 'strony istnieją');
	Harness::same($home, (int) get_post($child)->post_parent, 'rodzic');
	Harness::same('draft', get_post($home)->post_status, 'szkic');
	Harness::same('O nas & zespół', get_post($child)->post_title, 'tytuł');
	Harness::same($state['source'], get_post_meta($home, WpImporter::META_SOURCE, true), 'znacznik');
	Harness::same(count($document['pages']), (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE 'page:%%'", WpImporter::META_KEY)), 'liczba stron');
});

Harness::test('Grupy ACF: klucze deterministyczne, pola zgodne z mapowaniem', function () use ($document, $sectionUuid, $fields, $name, $ids) {
	$group = acf_get_field_group(WpImporter::groupKey($document, $sectionUuid('hero')));

	Harness::assert(is_array($group) && str_starts_with($group['key'], 'group_fmi_test_'), 'grupa');

	$wrapper = acf_get_fields($group)[0];

	Harness::same('group', $wrapper['type'], 'opakowanie sekcji');

	$sub = collect($wrapper['sub_fields'])->keyBy('name');

	foreach (['title', 'lead', 'button', 'photo', 'gallery'] as $alias) {
		$expected = $fields[Field::query()->findOrFail($ids['fields'][$alias])->uuid]['acf'];

		Harness::same($expected['type'], $sub[$expected['name']]['type'], "typ {$alias}");
		Harness::assert(str_starts_with($sub[$expected['name']]['key'], 'field_fmi_test_'), "klucz {$alias}");
	}

	Harness::same('array', $sub[$name('photo')]['return_format'], 'ustawienia z mapowania');
	Harness::same(120, (int) $sub[$name('title')]['maxlength'], 'ustawienia pola');
});

Harness::test('Rekordy ACF mają znacznik źródła', function () use ($state) {
	global $wpdb;

	$untagged = $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type IN ('acf-field-group', 'acf-field')
		AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s)",
		WpImporter::META_SOURCE,
		$state['source'],
	));

	Harness::same(0, (int) $untagged, 'bez znacznika');
});

Harness::test('get_field: tekst, wielowierszowy, WYSIWYG, link', function () use ($hero, $name) {
	Harness::same('Tytuł strony', $hero[$name('title')], 'tekst');
	Harness::same("Pierwszy wiersz\nDrugi wiersz", $hero[$name('lead')], 'wielowierszowy');
	Harness::same('Kontakt', $hero[$name('button')]['title'], 'link: tekst');
	Harness::same('https://example.com/kontakt', $hero[$name('button')]['url'], 'link: adres');
	Harness::assert(str_contains((string) $hero['opis'], '<strong>projektu</strong>'), 'WYSIWYG');
});

Harness::test('get_field: zdjęcie i galeria z ALT, kolejność', function () use ($hero, $name, $document, $assets, $ids) {
	$photo = $hero[$name('photo')];

	Harness::assert(is_array($photo) && (int) $photo['ID'] > 0, 'zdjęcie');
	Harness::same('image/png', $photo['mime_type'], 'mime');

	$gallery = $hero[$name('gallery')];
	$entry = collect($document['values'])->firstWhere('field_id', Field::query()->findOrFail($ids['fields']['gallery'])->uuid);

	Harness::same(2, count($gallery), 'galeria');
	Harness::same($assets[$entry['value'][0]]['sha256'], get_post_meta($gallery[0]['ID'], WpImporter::META_SHA, true), 'kolejność');
	Harness::same('Pierwsze', $gallery[0]['alt'], 'ALT');
	Harness::same('Podpis', $gallery[0]['caption'], 'podpis');
});

Harness::test('get_field: grupa, repeater, zagnieżdżony repeater, puste wiersze', function () use ($values, $name) {
	$group = $values[$name('group')];
	$rows = $group[$name('repeater')];

	Harness::same('Nasze wartości', $group['naglowek_listy'], 'pole w grupie');
	Harness::same(2, count($rows), 'wiersze');
	Harness::same('Pierwsza wartość', $rows[0]['tytul'], 'pole wiersza');
	Harness::assert(is_array($rows[0]['ikona']) && (int) $rows[0]['ikona']['ID'] > 0, 'zdjęcie w wierszu');
	Harness::same('Punkt w wierszu', $rows[0][$name('nested')][0]['punkt'], 'zagnieżdżony');
	Harness::same('', (string) $rows[1]['tytul'], 'pusty wiersz');
});

Harness::test('Pola o tej samej nazwie w dwóch sekcjach nie kolidują', function () use ($hero, $values, $name) {
	Harness::same('Tytuł strony', $hero[$name('title')], 'pierwsza sekcja');
	Harness::same('Nagłówek drugiej sekcji', $values[$name('title')], 'druga sekcja');
});

Harness::test('Pole ukryte zaimportowane, niezmapowane i archiwalne nie', function () use ($hero, $name, $ids) {
	global $wpdb;

	Harness::assert(array_key_exists($name('hidden'), $hero), 'ukryte');
	Harness::same(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name = %s", WpImporter::fieldKey(Field::query()->findOrFail($ids['fields']['unmapped'])->uuid))), 'niezmapowane');
	Harness::same(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name = %s", WpImporter::fieldKey(Field::query()->findOrFail($ids['fields']['old'])->uuid))), 'archiwalne');
});

Harness::test('Media: 4 załączniki, pliki istnieją, sha256 w meta', function () use ($state, $document) {
	global $wpdb;

	$rows = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", WpImporter::META_SHA));

	Harness::same(4, count($rows), 'liczba');

	foreach ($rows as $id) {
		Harness::assert(wp_attachment_is_image((int) $id), 'obraz');
		Harness::same(get_post_meta((int) $id, WpImporter::META_SHA, true), hash_file('sha256', get_attached_file((int) $id)), 'suma pliku');
		Harness::same($state['source'], get_post_meta((int) $id, WpImporter::META_SOURCE, true), 'znacznik');
	}

	file_put_contents('/tmp/fmi-stage12-meta.json', json_encode(array_map(fn ($id) => get_attached_file((int) $id), $rows)));
});

exit(Harness::summary('ETAP 12 — get_field'));
