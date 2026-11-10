<?php

/**
 * Schemat strony na prawdziwej bazie: wgranie, automat, ręczne poprawki (także po ponownym „Dopasuj” i nowej
 * wersji schematu), nieaktualne dopasowania, ostrzeżenia, eksport z nazwami ACF i zgodność z regułami wtyczki.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Page;
use App\Models\Project;
use App\Models\SiteMapping;
use App\Services\ContentExportBuilder;
use App\Services\ContentExportValidator;
use App\Services\ExportService;
use App\Services\ExportValidator;
use App\Services\SiteMappingService;
use App\Support\SiteSchemaIndex;
use Illuminate\Validation\ValidationException;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$ids = json_decode((string) file_get_contents('/tmp/fmi-site-schema-ids.json'), true);
$project = Project::query()->findOrFail((int) $manifest['projects']['a']);
$projectB = Project::query()->findOrFail((int) $manifest['projects']['b']);
$home = Page::query()->findOrFail($ids['pages']['home']);
$contact = Page::query()->findOrFail($ids['pages']['contact']);
$admin = get_userdata((int) $manifest['users']['admin']);
$site = app(SiteMappingService::class);
$exports = app(ExportService::class);
$fixture = require __DIR__ . '/fixture.php';
$F = $ids['fields'];
$S = $ids['sections'];

$resolve = fn () => $site->resolve($project);
$path = fn (array $resolved, string $field) => $resolved['fields'][$F[$field]]['acf']['path'] ?? null;
$mapping = fn (string $type, int $id) => SiteMapping::query()->where('mappable_type', $type)->where('mappable_id', $id)->first();

/**
 * Reguły importu wtyczki Fill Me In Sync (Fmi\PageImport, Fmi\Package): blok musi istnieć, ścieżka pola musi
 * wskazywać pole bloku, a ścieżki w wierszach repeatera zaczynać się od `ścieżka_repeatera.*.`.
 *
 * @return string[] ostrzeżenia, jakie pokazałaby wtyczka dla zmapowanych pól
 */
$plugin = function (array $payload, SiteSchemaIndex $index): array {
	$warnings = [];
	$fields = array_column($payload['fields'], null, 'id');
	$sections = array_column($payload['sections'], null, 'id');

	$walk = function (array $values, string $prefix, string $block) use (&$walk, &$warnings, $fields, $index) {
		$paths = array_column($index->fields($block), 'path');

		foreach ($values as $value) {
			$acf = $fields[$value['field_id']]['acf'];

			if (! $acf['mapped']) {
				continue;
			}

			if ($prefix !== '' && ! str_starts_with($acf['path'], $prefix)) {
				$warnings[] = "Ścieżka {$acf['path']} poza repeaterem {$prefix}";
			}

			if (! in_array($acf['path'], $paths, true)) {
				$warnings[] = "Pole „{$acf['path']}” nie istnieje w bloku {$block}";
			}

			if ($fields[$value['field_id']]['type'] === 'repeater') {
				foreach ((array) $value['value'] as $row) {
					$walk($row['values'], $acf['path'] . '.*.', $block);
				}
			}
		}
	};

	$bySection = [];

	foreach ($payload['values'] as $value) {
		$bySection[$fields[$value['field_id']]['section_id']][] = $value;
	}

	foreach ($sections as $id => $section) {
		if ($section['hidden']) {
			continue;
		}

		$block = $section['mapping']['acf_block'];

		if (! $index->block($block)) {
			$warnings[] = "Blok „{$block}” nie istnieje w motywie ({$section['name']})";

			continue;
		}

		$walk($bySection[$id] ?? [], '', $block);
	}

	return $warnings;
};

Harness::test('Wgrywanie: inny format i zły JSON odrzucone z czytelnym komunikatem', function () use ($site) {
	Harness::throws(ValidationException::class, fn () => $site->parse('{"format":"fmi-export"}'), 'inny format', 'fmi-site-schema');
	Harness::throws(ValidationException::class, fn () => $site->parse('nie json'), 'zły JSON', 'nie jest poprawnym JSON');
	Harness::throws(ValidationException::class, fn () => $site->parse('{"format":"fmi-site-schema","schema_version":"2.0","site":{"url":"x"},"blocks":[],"pages":[]}'), 'wersja 2.x', 'wersje 1.x');
});

Harness::test('Wgranie schematu: zapis przy projekcie i automatyczne dopasowanie', function () use ($site, $project, $admin, $fixture, $home, $contact, $S, $resolve) {
	$site->upload($project, json_encode($fixture), $admin);
	$schema = $site->schema($project);

	Harness::same('https://greenq.example/', $schema->site_url, 'site.url');
	Harness::assert($schema->uploaded_at !== null, 'data wgrania');

	$resolved = $resolve();
	Harness::same('12', $resolved['pages'][$home->id]['target'], 'strona główna po slugu');
	Harness::same(['post_type' => 'page', 'slug' => 'strona-glowna', 'template' => null], $resolved['pages'][$home->id]['mapping'], 'mapowanie strony ze schematu');
	Harness::same(null, $resolved['pages'][$contact->id]['target'], 'kontakt — nowa strona');
	Harness::same('new', $resolved['pages'][$contact->id]['status'], 'status nowej strony');

	$blocks = fn (string $key) => [$resolved['sections'][$S[$key]]['block'], $resolved['sections'][$S[$key]]['position'], $resolved['sections'][$S[$key]]['occurrence']];
	Harness::same(['acf/hero', 0, 0], $blocks('hero'), 'hero');
	Harness::same(['acf/slider', 1, 0], $blocks('slider'), 'slider mimo dodatkowej sekcji przed nim');
	Harness::same(['acf/cta', 3, 0], $blocks('cta1'), 'pierwsze CTA');
	Harness::same(['acf/cta', 4, 1], $blocks('cta2'), 'drugie CTA — occurrence 1');
	Harness::assert($resolved['sections'][$S['extra']]['position'] === null, 'dodatkowa sekcja nie zajmuje bloku strony');
	Harness::same('acf/features', $resolved['sections'][$S['features']]['block'], 'nowa strona — blok z motywu');
});

Harness::test('Ścieżki ACF: grupa przez grupę, grupa przezroczysta, repeatery zagnieżdżone', function () use ($resolve, $path, $F) {
	$resolved = $resolve();

	Harness::same('g_top.header', $path($resolved, 'heroTitle'), 'płaskie pole → pole w grupie ACF');
	Harness::same('button', $path($resolved, 'heroButton'), 'link');
	Harness::same('g_top', $path($resolved, 'sliderGroup'), 'grupa ↔ grupa');
	Harness::same('r_cards.*.title', $path($resolved, 'cardTitle'), 'pole w repeaterze');
	Harness::same(true, $resolved['fields'][$F['ctaGroup']]['transparent'], 'grupa bez odpowiednika');
	Harness::same('header', $path($resolved, 'ctaTitle'), 'pole z grupy przezroczystej');
	Harness::same('r_list.*.r_cards.*.title', $path($resolved, 'innerTitle'), 'repeater w repeaterze');
	Harness::same('r_list.*.r_cards.*.icon', $path($resolved, 'innerIcon'), 'zdjęcie w repeaterze w repeaterze');
});

Harness::test('Ręczne poprawki: tylko zmienione listy, przetrwają „Dopasuj automatycznie”', function () use ($site, $project, $home, $admin, $F, $S, $mapping, $resolve) {
	$count = $site->saveManual($project, $home, [
		'page' => '12', 'original_page' => '12',
		'sections' => [$S['extra'] => 'acf/text|', $S['hero'] => 'acf/hero|0'],
		'original_sections' => [$S['extra'] => 'x', $S['hero'] => 'acf/hero|0'],
		'fields' => [$F['heroText'] => '', $F['heroTitle'] => 'field_hero_g_top_header'],
		'original_fields' => [$F['heroText'] => 'field_hero_g_top_text', $F['heroTitle'] => 'field_hero_g_top_header'],
	], $admin);

	Harness::same(2, $count, 'dwie zmiany');
	Harness::same('manual', $mapping(SiteMapping::SECTION, $S['extra'])->source, 'sekcja ręcznie');
	Harness::same('auto', $mapping(SiteMapping::SECTION, $S['hero'])->source, 'niezmieniona sekcja zostaje automatem');
	Harness::same('auto', $mapping(SiteMapping::FIELD, $F['heroTitle'])->source, 'niezmienione pole zostaje automatem');

	$site->autoMatch($project, $admin);
	$resolved = $resolve();

	Harness::same('acf/text', $resolved['sections'][$S['extra']]['block'], 'ręczny blok po ponownym automacie');
	Harness::same('skipped', $resolved['fields'][$F['heroText']]['status'], '„nie importuj” po ponownym automacie');
	Harness::same('manual', $resolved['sections'][$S['extra']]['status'], 'status ręczny');
});

Harness::test('Walidacja zmian: pole niezgodnego typu i obca sekcja odrzucone', function () use ($site, $project, $home, $admin, $F, $S, $contact) {
	Harness::throws(ValidationException::class, fn () => $site->saveManual($project, $home, ['fields' => [$F['heroImage'] => 'field_hero_g_top_header'], 'original_fields' => [$F['heroImage'] => '']], $admin), 'zdjęcie → tekst', 'nie pasuje');
	Harness::throws(ValidationException::class, fn () => $site->saveManual($project, $contact, ['sections' => [$S['hero'] => 'acf/hero|0'], 'original_sections' => [$S['hero'] => '']], $admin), 'sekcja innej podstrony', 'spoza tej podstrony');
	Harness::throws(ValidationException::class, fn () => $site->saveManual($project, $home, ['sections' => [$S['hero'] => 'acf/nie-ma|'], 'original_sections' => [$S['hero'] => '']], $admin), 'nieznany blok', 'nie istnieje w schemacie');
});

Harness::test('Ostrzeżenia: wymagane pole ACF bez źródła i pole z treścią bez pary', function () use ($site, $project, $home, $admin, $F, $resolve) {
	$site->saveManual($project, $home, ['fields' => [$F['heroTitle'] => ''], 'original_fields' => [$F['heroTitle'] => 'field_hero_g_top_header']], $admin);
	$resolved = $resolve();
	$labels = fn (string $key) => array_column($resolved['warnings'][$key], 'label');

	Harness::assert(in_array('Frame 347 › Nagłówek › Tytuł (g_top › header)', $labels('required'), true), 'wymagane g_top.header bez źródła: ' . json_encode($labels('required'), JSON_UNESCAPED_UNICODE));
	Harness::same([], array_values(array_filter($labels('unmapped'), fn ($label) => str_contains($label, 'Zachowaj'))), '„nie importuj” to świadoma decyzja, nie brak');

	$site->restore($project, $home, SiteMapping::FIELD, $F['heroTitle'], $admin);
	Harness::same('g_top.header', $resolve()['fields'][$F['heroTitle']]['acf']['path'] ?? null, 'przywrócony automat');
	Harness::same([], array_values(array_filter(array_column($resolve()['warnings']['required'], 'label'), fn ($label) => str_contains($label, 'Frame 347'))), 'bez ostrzeżenia po przywróceniu');
});

Harness::test('Nowa wersja schematu: ręczne zostają, wskazujące usunięty blok są nieaktualne', function () use ($site, $project, $admin, $fixture, $F, $S, $mapping, $resolve) {
	$next = $fixture;
	$next['blocks'] = array_values(array_filter($next['blocks'], fn ($block) => $block['name'] !== 'acf/text'));
	$next['site']['url'] = 'https://greenq.example/v2/';
	$site->upload($project, json_encode($next), $admin);
	$resolved = $resolve();

	Harness::same('https://greenq.example/v2/', $site->schema($project)->site_url, 'schemat zastąpiony');
	Harness::same('manual', $mapping(SiteMapping::SECTION, $S['extra'])->source, 'ręczne dopasowanie nie skasowane');
	Harness::same('stale', $resolved['sections'][$S['extra']]['status'], 'nieaktualne (czerwone)');
	Harness::same(null, $resolved['sections'][$S['extra']]['block'], 'nieaktualny blok nie trafia do eksportu');
	Harness::assert(count($resolved['warnings']['stale']) >= 1, 'ostrzeżenie o nieaktualnym');
	Harness::same('skipped', $resolved['fields'][$F['heroText']]['status'], 'ręczne „nie importuj” zostaje');

	$site->upload($project, json_encode($fixture), $admin);
	Harness::same('manual', $resolve()['sections'][$S['extra']]['status'], 'po powrocie bloku znów aktualne');
});

Harness::test('Przywróć automat dla sekcji usuwa ręczne dopasowania sekcji i jej pól', function () use ($site, $project, $home, $admin, $S, $mapping) {
	$site->restore($project, $home, SiteMapping::SECTION, $S['extra'], $admin);

	Harness::same('auto', $mapping(SiteMapping::SECTION, $S['extra'])->source, 'sekcja wraca do automatu');
	Harness::throws(ValidationException::class, fn () => $site->restore($project, $home, SiteMapping::SECTION, $S['features'], $admin), 'sekcja innej podstrony', 'nie należy');
});

Harness::test('Eksport z mapowaniem: nazwy ACF, ścieżki, occurrence, schema, braki', function () use ($exports, $project, $site, $F, $S, $plugin, $resolve) {
	$payload = $exports->build($project);
	$resolved = $resolve();

	Harness::same([], app(ExportValidator::class)->validate($payload), 'export.json zgodny ze schematem');
	Harness::same(ExportService::SCHEMA_VERSION, $payload['schema_version'], 'wersja');
	Harness::same('https://greenq.example/', $payload['schema']['site_url'], 'schema.site_url');
	Harness::assert(is_string($payload['schema']['uploaded_at']), 'schema.uploaded_at');

	$fields = [];

	foreach ($payload['fields'] as $field) {
		$fields[$field['id']] = $field;
	}

	$byId = fn (string $key) => $fields[\App\Models\Field::query()->findOrFail($F[$key])->uuid]['acf'];
	Harness::same(['name' => 'header', 'type' => 'text', 'path' => 'g_top.header', 'mapped' => true, 'key' => 'field_hero_g_top_header'], array_intersect_key($byId('heroTitle'), array_flip(['name', 'type', 'path', 'key', 'mapped'])), 'pole ze schematu');
	Harness::same('r_list.*.r_cards.*.icon', $byId('innerIcon')['path'], 'zagnieżdżony repeater');
	Harness::same(true, $byId('ctaGroup')['transparent'] ?? null, 'grupa przezroczysta oznaczona');

	$sections = array_column($payload['sections'], null, 'name');
	Harness::same(['acf_block' => 'acf/cta', 'occurrence' => 1], $sections['Frame 89']['mapping'], 'drugie CTA');
	Harness::same(['acf_block' => 'acf/hero', 'occurrence' => 0], $sections['Frame 347']['mapping'], 'hero');

	$unmapped = array_column($payload['missing']['unmapped'], null, 'field_id');
	Harness::assert(! isset($unmapped[\App\Models\Field::query()->find($F['ctaGroup'])->uuid]), 'grupa przezroczysta nie jest brakiem');

	foreach ($payload['missing']['unmapped'] as $item) {
		Harness::assert(isset($item['label'], $item['has_content'], $item['section_id']), 'wpis braku z etykietą, sekcją i informacją o treści');
	}

	// Sekcja bez pary (galeria, której motyw nie ma) to świadomy brak — wtyczka może o nim ostrzec, reszta nie.
	$extra = \App\Models\Section::query()->findOrFail($S['extra']);
	Harness::same(null, $resolved['sections'][$S['extra']]['block'], 'sekcja z galerią bez bloku');
	Harness::same([], array_values(array_filter($plugin($payload, $resolved['index']), fn ($warning) => ! str_contains($warning, '(' . $extra->name . ')'))), 'wtyczka: bez „blok nie istnieje” / „pole nie istnieje” dla dopasowanych');
	Harness::assert(isset($unmapped[\App\Models\Field::query()->find($F['extraGallery'])->uuid]), 'pole sekcji bez bloku w missing.unmapped');
});

Harness::test('content.json bez zmian i projekt bez schematu eksportuje się jak dotąd', function () use ($exports, $project, $projectB) {
	$content = app(ContentExportBuilder::class)->build($project, $exports->pagesFor($project));
	Harness::same([], app(ContentExportValidator::class)->validate($content), 'content.json poprawny');
	Harness::same(ContentExportBuilder::SCHEMA_VERSION, $content['schema_version'], 'wersja content.json bez zmian');

	$payload = $exports->build($projectB);
	Harness::same([], app(ExportValidator::class)->validate($payload), 'export.json projektu bez schematu');
	Harness::assert(! array_key_exists('schema', $payload), 'bez klucza schema');

	foreach ($payload['sections'] as $section) {
		Harness::same(['acf_block'], array_keys($section['mapping']), 'blok z nazwy sekcji, bez occurrence');
	}
});

exit(Harness::summary('Schemat strony — serwis i eksport'));
