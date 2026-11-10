<?php

/**
 * Eksport uniwersalny `content.json` (format fmi-content 2.x): drzewo, stany pól, repeatery, media, zakres podstron,
 * paczka ZIP (rozpakowanie i odczyt), brak zmian w treści. Zmiany treści tylko w transakcji wycofywanej.
 *
 * FMI_EXPORT_EXAMPLE=<ścieżka> zapisuje content.json projektu testowego (do odświeżenia przykładu w docs/).
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Enums\ExportStatus;
use App\Models\Field;
use App\Models\FieldValue;
use App\Models\Page;
use App\Models\Project;
use App\Services\ContentExportBuilder;
use App\Services\ContentExportValidator;
use App\Services\ContentService;
use App\Services\ExportService;
use App\Services\ExportValidator;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$ids = json_decode((string) file_get_contents('/tmp/fmi-stage11-ids.json'), true);
$project = Project::query()->findOrFail((int) $manifest['projects']['a']);
$home = Page::query()->findOrFail((int) $manifest['pages']['a']);
$child = Page::query()->findOrFail((int) $ids['pages']['child']);
$admin = get_userdata((int) $manifest['users']['admin']);
$exports = app(ExportService::class);
$builder = app(ContentExportBuilder::class);
$validator = app(ContentExportValidator::class);
$uuid = fn (string $name) => Field::query()->findOrFail($ids['fields'][$name])->uuid;
$doc = $builder->build($project, $exports->pagesFor($project));

if ($target = getenv('FMI_EXPORT_EXAMPLE')) {
	file_put_contents($target, ExportService::encode($doc));
}

/** Pierwsze wystąpienie pola w drzewie (pola sekcji, grup i wierszy). */
$find = function (array $fields, string $id) use (&$find): ?array {
	foreach ($fields as $field) {
		if ($field['id'] === $id) {
			return $field;
		}

		$nested = $find($field['fields'] ?? [], $id);

		foreach ($field['rows'] ?? [] as $row) {
			$nested ??= $find($row['fields'], $id);
		}

		if ($nested) {
			return $nested;
		}
	}

	return null;
};
$all = fn (array $doc) => array_merge(...array_map(fn ($page) => array_merge([], ...array_map(fn ($s) => $s['fields'], $page['sections'])), $doc['pages']));
$field = fn (string $name, ?array $in = null) => $find($all($in ?? $doc), $uuid($name));

Harness::test('content.json: schemat 2.0 i spójność odwołań', function () use ($doc, $validator) {
	Harness::same([], $validator->validate($doc), 'błędy walidacji');
	Harness::same('fmi-content', $doc['format'], 'format');
	Harness::same(ContentExportBuilder::SCHEMA_VERSION, $doc['schema_version'], 'wersja');
	Harness::same(['format', 'schema_version', 'generator', 'exported_at', 'scope', 'project', 'summary', 'pages', 'media', 'missing'], array_keys($doc), 'klucze główne');
	Harness::same(['type' => 'project'], ['type' => $doc['scope']['type']], 'zakres: cały projekt');
});

Harness::test('Drzewo: projekt → podstrony → sekcje → pola w kolejności', function () use ($doc, $home, $child) {
	$pages = array_column($doc['pages'], null, 'id');

	Harness::same($home->uuid, $doc['pages'][0]['id'], 'pierwsza podstrona');
	Harness::same($home->uuid, $pages[$child->uuid]['parent_id'], 'podstrona podrzędna wskazuje rodzica');
	Harness::assert(! in_array('Stara strona', array_column($doc['pages'], 'name'), true), 'bez archiwum');

	$sections = array_column($pages[$home->uuid]['sections'], 'name');
	Harness::assert(array_search('Główna sekcja', $sections, true) < array_search('Wartości', $sections, true), 'kolejność sekcji');

	foreach ($doc['pages'] as $page) {
		foreach ($page['sections'] as $section) {
			$positions = array_column($section['fields'], 'position');
			$sorted = $positions;
			sort($sorted);
			Harness::same($sorted, $positions, "pozycje pól w sekcji {$section['name']}");
		}
	}
});

Harness::test('Każde pole: identyfikator, nazwa techniczna, etykieta, typ, stan i wartość', function () use ($doc, $all) {
	$walk = function (array $fields) use (&$walk) {
		foreach ($fields as $field) {
			foreach (['id', 'key', 'label', 'type', 'state'] as $key) {
				Harness::assert(array_key_exists($key, $field), "pole {$field['label']}: {$key}");
			}

			match ($field['type']) {
				'group' => $walk($field['fields']),
				'repeater' => array_map(fn ($row) => $walk($row['fields']), $field['rows']),
				default => Harness::assert(array_key_exists('value', $field), "pole {$field['label']}: value"),
			};
		}
	};

	$walk($all($doc));
});

Harness::test('Stany: uzupełnione, nieuzupełnione, celowo wyczyszczone', function () use ($field, $project, $exports, $builder, $ids, $admin) {
	Harness::same('filled', $field('title')['state'], 'tekst z treścią');
	Harness::same('Tytuł strony', $field('title')['value'], 'wartość');
	Harness::same('empty', $field('empty')['state'], 'nigdy nieuzupełnione');
	Harness::same(null, $field('empty')['value'], 'pusta wartość');

	DB::beginTransaction();

	try {
		$lead = Field::query()->findOrFail($ids['fields']['lead']);
		$version = (int) FieldValue::query()->where('field_id', $lead->id)->where('row_key', '')->value('version');
		app(ContentService::class)->save($lead, '', $version, Actor::user($admin));

		$after = $builder->build($project, $exports->pagesFor($project));

		Harness::same('cleared', $field('lead', $after)['state'], 'wyczyszczone po zapisie');
		Harness::same(null, $field('lead', $after)['value'], 'bez wartości');
		Harness::assert($after['summary']['cleared'] >= 1, 'licznik wyczyszczonych');
	} finally {
		DB::rollBack();
	}
});

Harness::test('Grupa, repeater, zagnieżdżony repeater i kolejność wierszy', function () use ($field, $uuid, $ids) {
	$group = $field('group');

	Harness::same('group', $group['type'], 'grupa');
	Harness::assert(! array_key_exists('value', $group), 'grupa bez własnej wartości');
	Harness::same('Nagłówek listy', $group['fields'][0]['label'], 'pierwsze pole w grupie');
	Harness::same('filled', $group['state'], 'stan grupy z pól w środku');
	Harness::same($uuid('repeater'), $group['fields'][1]['id'], 'repeater w grupie');

	$repeater = $field('repeater');

	Harness::same([$ids['rows']['a'], $ids['rows']['b']], array_column($repeater['rows'], 'id'), 'wiersze');
	Harness::same([0, 1], array_column($repeater['rows'], 'position'), 'pozycje');

	$rowA = array_column($repeater['rows'][0]['fields'], null, 'id');
	$rowB = array_column($repeater['rows'][1]['fields'], null, 'id');

	Harness::same('Pierwsza wartość', $rowA[$uuid('item_title')]['value'], 'pole wiersza');
	Harness::same('empty', $rowB[$uuid('item_title')]['state'], 'puste pole w drugim wierszu');
	Harness::same('Punkt w wierszu', $rowA[$uuid('nested')]['rows'][0]['fields'][0]['value'], 'wiersz zagnieżdżony');
});

Harness::test('Media: odwołania do plików w media/, galeria, przycisk', function () use ($doc, $field) {
	$media = array_column($doc['media'], 'path', 'id');

	Harness::same(4, count($media), 'pliki z pól i wierszy');

	foreach ($doc['media'] as $item) {
		Harness::same("media/{$item['sha256']}.png", $item['path'], 'ścieżka = suma');
	}

	$photo = $field('photo');
	Harness::same('filled', $photo['state'], 'zdjęcie');
	Harness::same($media[$photo['value']['media_id']], $photo['value']['path'], 'odwołanie do media');
	Harness::same(null, $photo['source_link'], 'bez linku zewnętrznego');

	$gallery = $field('gallery');
	Harness::same(2, count($gallery['value']), 'galeria');
	Harness::same(['label' => 'Kontakt', 'url' => 'https://example.com/kontakt'], $field('button')['value'], 'przycisk');
});

Harness::test('Braki i podsumowanie', function () use ($doc) {
	$reasons = array_count_values(array_column($doc['missing'], 'reason'));

	Harness::assert(($reasons['empty'] ?? 0) >= 2, 'wymagane puste');
	Harness::same(1, $reasons['too_few'] ?? 0, 'za mało zdjęć');
	Harness::same(1, $reasons['missing_alt'] ?? 0, 'brak ALT');
	Harness::same(count($doc['missing']), $doc['summary']['missing'], 'licznik braków');
	Harness::same(count($doc['media']), $doc['summary']['media'], 'licznik zdjęć');
	Harness::same(count($doc['pages']), $doc['summary']['pages'], 'licznik podstron');
	Harness::same(array_sum(array_map(fn ($p) => count($p['sections']), $doc['pages'])), $doc['summary']['sections'], 'licznik sekcji');
});

Harness::test('Niezależny od WordPressa i bez sekretów', function () use ($doc, $ids) {
	$json = ExportService::encode($doc);

	Harness::assert(! str_contains($json, '"acf'), 'bez kluczy ACF');
	Harness::assert(! str_contains($json, 'acf/'), 'bez nazw bloków');
	Harness::assert(! str_contains($json, '"post_type"'), 'bez typów wpisów');
	Harness::assert(! str_contains($json, 'figma.com'), 'bez adresów Figmy');
	Harness::assert(! str_contains($json, $ids['token']), 'bez tokenu klienta');
	Harness::assert(! str_contains($json, '/Users/'), 'bez ścieżek serwera');
	Harness::assert(! preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[a-z]{2,}/', $json), 'bez e-maili');
});

Harness::test('Zakres: wybrane podstrony (bez rodzica) w obu dokumentach', function () use ($project, $exports, $builder, $validator, $child) {
	$pages = $exports->pagesFor($project, [$child->uuid]);
	$doc = $builder->build($project, $pages, false);
	$acf = $exports->build($project, $pages);

	Harness::same([$child->uuid], array_column($doc['pages'], 'id'), 'jedna podstrona');
	Harness::same(null, $doc['pages'][0]['parent_id'], 'rodzic spoza eksportu → korzeń');
	Harness::same(['type' => 'pages', 'page_ids' => [$child->uuid]], $doc['scope'], 'zakres');
	Harness::same([], $validator->validate($doc), 'content.json poprawny');
	Harness::same([$child->uuid], array_column($acf['pages'], 'id'), 'export.json: ta sama podstrona');
	Harness::same([], app(ExportValidator::class)->validate($acf), 'export.json poprawny');
});

Harness::test('Paczka ZIP: rozpakowanie, odczyt JSON, pliki zgodne z sumami, treść bez zmian', function () use ($project, $exports, $admin, $validator) {
	$before = [FieldValue::query()->where('project_id', $project->id)->sum('version'), (string) FieldValue::query()->where('project_id', $project->id)->max('updated_at'), $project->fresh()->status];
	$export = $exports->run($project, $admin);

	Harness::same(ExportStatus::Completed, $export->status, 'status');
	Harness::same(['type' => 'project'], $export->summary['scope'], 'zakres w historii');
	Harness::same(ContentExportBuilder::SCHEMA_VERSION, $export->summary['content_schema_version'], 'wersja formatu w historii');

	$disk = Storage::disk('fmi');
	$zipPath = $disk->path(dirname($export->file_path) . '/export.zip');
	$dir = sys_get_temp_dir() . '/fmi-unzip-' . bin2hex(random_bytes(4));
	mkdir($dir, 0700);

	try {
		$zip = new ZipArchive;
		Harness::same(true, $zip->open($zipPath, ZipArchive::RDONLY), 'ZIP się otwiera');

		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = $zip->getNameIndex($i);
		}

		Harness::assert(in_array('content.json', $names, true) && in_array('export.json', $names, true), 'content.json i export.json');
		Harness::same([], array_values(array_filter($names, fn ($n) => ! in_array($n, ['content.json', 'export.json'], true) && ! preg_match('~^media/[0-9a-f]{64}\.[a-z0-9]+$~', $n))), 'tylko media/ poza JSON');
		Harness::same(true, $zip->extractTo($dir), 'rozpakowanie');
		$zip->close();

		$content = json_decode((string) file_get_contents("{$dir}/content.json"), true, 512, JSON_THROW_ON_ERROR);

		Harness::same([], $validator->validate($content), 'odczytany content.json poprawny');
		Harness::same($export->summary['content_checksum'], hash_file('sha256', "{$dir}/content.json"), 'suma content.json');

		foreach ($content['media'] as $item) {
			$file = "{$dir}/{$item['path']}";
			Harness::assert(is_file($file), "plik {$item['path']}");
			Harness::same($item['sha256'], hash_file('sha256', $file), 'suma pliku');
			Harness::same($item['size'], filesize($file), 'rozmiar pliku');
		}
	} finally {
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
			$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		}
		rmdir($dir);
	}

	$after = [FieldValue::query()->where('project_id', $project->id)->sum('version'), (string) FieldValue::query()->where('project_id', $project->id)->max('updated_at'), $project->fresh()->status];

	Harness::same($before, $after, 'treści, wersje i status projektu bez zmian');
});

Harness::test('Historia: zakres wybranych podstron z nazwami; nieistniejąca podstrona → błąd', function () use ($project, $exports, $admin, $home) {
	$export = $exports->run($project, $admin, [$home->uuid]);

	Harness::same(ExportStatus::Completed, $export->status, 'status');
	Harness::same(['type' => 'pages', 'pages' => [['id' => $home->uuid, 'name' => $home->name]]], $export->summary['scope'], 'zakres z nazwą');

	$content = json_decode(Storage::disk('fmi')->get(dirname($export->file_path) . '/content.json'), true);
	Harness::same([$home->uuid], array_column($content['pages'], 'id'), 'tylko wybrana podstrona');

	$failed = $exports->run($project, $admin, ['00000000-0000-4000-8000-000000000000']);

	Harness::same(ExportStatus::Failed, $failed->status, 'status błędu');
	Harness::same('Wybrane podstrony nie istnieją albo są w archiwum.', $failed->error_message, 'komunikat');
});

Harness::test('Przykład z dokumentacji przechodzi walidację', function () use ($validator) {
	$example = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/examples/content.example.json'), true, 512, JSON_THROW_ON_ERROR);

	Harness::same([], $validator->validate($example), 'docs/examples/content.example.json');
});

exit(Harness::summary('ETAP 11 — content.json'));
