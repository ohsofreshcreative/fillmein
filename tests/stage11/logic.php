<?php

/**
 * Etap 11 — mapowanie ACF, budowa eksportu, determinizm, raport braków. Zapisy mapowań w transakcji wycofywanej.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Enums\FieldType;
use App\Models\Field;
use App\Models\Page;
use App\Models\Project;
use App\Models\Section;
use App\Models\WpMapping;
use App\Services\AcfMappingService;
use App\Services\ExportService;
use App\Services\ExportValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$ids = json_decode((string) file_get_contents('/tmp/fmi-stage11-ids.json'), true);
$project = Project::query()->findOrFail((int) $manifest['projects']['a']);
$home = Page::query()->findOrFail((int) $manifest['pages']['a']);
$admin = get_userdata((int) $manifest['users']['admin']);
$mapping = app(AcfMappingService::class);
$exports = app(ExportService::class);
$payload = $exports->build($project);
$fields = collect($payload['fields'])->keyBy('id');
$uuid = fn (string $name) => Field::query()->findOrFail($ids['fields'][$name])->uuid;
$field = fn (string $name) => $fields[$uuid($name)];
$values = collect($payload['values'])->keyBy('field_id');

Harness::test('C1: każdy FieldType ma mapowanie albo jawny status nieobsługiwany', function () use ($mapping) {
	foreach (FieldType::cases() as $type) {
		$definition = $mapping->definition($type);

		if ($definition['supported']) {
			Harness::assert(is_string($definition['acf_type']) && $definition['acf_type'] !== '', "{$type->value}: typ ACF");
			Harness::assert(in_array($definition['acf_type'], $definition['alternatives'], true), "{$type->value}: domyślny na liście");
		} else {
			Harness::assert(is_string($definition['message']) && $definition['message'] !== '', "{$type->value}: komunikat");
		}
	}
});

Harness::test('C1: domyślne typy i ustawienia', function () use ($mapping) {
	$expected = ['text' => 'text', 'textarea' => 'textarea', 'wysiwyg' => 'wysiwyg', 'link' => 'link', 'image' => 'image', 'gallery' => 'gallery', 'group' => 'group', 'repeater' => 'repeater'];

	foreach (FieldType::cases() as $type) {
		Harness::same($expected[$type->value], $mapping->definition($type)['acf_type'], $type->value);
	}

	Harness::same('array', $mapping->definition(FieldType::Image)['settings']['return_format'], 'obraz: tablica');
});

Harness::test('C1: typy ACF istnieją w ACF Pro', function () use ($mapping) {
	$root = WP_PLUGIN_DIR . '/advanced-custom-fields-pro';

	foreach (FieldType::cases() as $type) {
		$acf = $mapping->definition($type)['acf_type'];
		$matches = [...(glob($root . "/includes/fields/class-acf-field-{$acf}.php") ?: []), ...(glob($root . "/pro/fields/class-acf-field-{$acf}.php") ?: [])];

		Harness::assert($matches !== [], "plik typu {$acf}");
	}
});

Harness::test('Nazwy: snake_case, prefiksy, litera na początku, unikalne w rodzicu', function () use ($mapping) {
	$make = fn (string $name, FieldType $type, int $id) => (new Field)->forceFill(['id' => $id, 'name' => $name, 'type' => $type]);

	Harness::same('naglowek_listy', $mapping->defaultName($make('nagłówek-listy', FieldType::Text, 1)), 'snake_case');
	Harness::same('g_wartosci', $mapping->defaultName($make('wartosci', FieldType::Group, 2)), 'grupa');
	Harness::same('r_lista', $mapping->defaultName($make('lista', FieldType::Repeater, 3)), 'repeater');
	Harness::same('r_lista', $mapping->defaultName($make('r-lista', FieldType::Repeater, 4)), 'bez podwójnego prefiksu');
	Harness::same('pole_2024', $mapping->defaultName($make('2024', FieldType::Text, 5)), 'cyfra na początku');

	$names = $mapping->names(collect([$make('tytul', FieldType::Text, 1), $make('Tytul', FieldType::Textarea, 2), $make('tytul-3', FieldType::Text, 3)]), collect());

	Harness::same(['tytul', 'tytul_2', 'tytul_3'], array_values($names), 'kolizje');

	$reserved = $mapping->names(collect([$make('a', FieldType::Text, 1), $make('b', FieldType::Text, 2)]), collect([2 => new WpMapping(['settings' => ['acf_name' => 'a']])]));

	Harness::same('a_2', $reserved[1], 'nadpisanie ma pierwszeństwo przed domyślną nazwą');
	Harness::same('a', $reserved[2], 'nadpisanie zachowane');
});

Harness::test('Ścieżki ACF: grupa, repeater, zagnieżdżony repeater', function () use ($field) {
	$group = $field('group')['acf']['name'];
	$repeater = $field('repeater')['acf']['name'];
	$nested = $field('nested')['acf']['name'];

	Harness::same("{$group}.{$repeater}", $field('repeater')['acf']['path'], 'repeater w grupie');
	Harness::same("{$group}.{$repeater}.*.{$field('item_title')['acf']['name']}", $field('item_title')['acf']['path'], 'pole w wierszu');
	Harness::same("{$group}.{$repeater}.*.{$nested}.*.{$field('point')['acf']['name']}", $field('point')['acf']['path'], 'pole w zagnieżdżonym wierszu');
});

Harness::test('C2: eksport projektu testowego przechodzi walidację', function () use ($payload) {
	Harness::same([], app(ExportValidator::class)->validate($payload), 'błędy walidacji');
	Harness::same('1.2', $payload['schema_version'], 'wersja');
	Harness::same(['schema_version', 'generator', 'exported_at', 'project', 'pages', 'sections', 'fields', 'values', 'assets', 'missing'], array_keys($payload), 'klucze główne');
});

Harness::test('Treść: wszystkie typy, repeatery jako tablice wierszy', function () use ($values, $uuid, $ids) {
	Harness::same('Tytuł strony', $values[$uuid('title')]['value'], 'tekst');
	Harness::same("Pierwszy wiersz\nDrugi wiersz", $values[$uuid('lead')]['value'], 'wielowierszowy');
	Harness::same(['label' => 'Kontakt', 'url' => 'https://example.com/kontakt'], $values[$uuid('button')]['value'], 'link');
	Harness::assert(is_string($values[$uuid('photo')]['value']), 'zdjęcie: UUID');
	Harness::same(2, count($values[$uuid('gallery')]['value']), 'galeria: lista');
	Harness::assert(! $values->has($uuid('group')), 'grupa bez własnej wartości');

	$rows = $values[$uuid('repeater')]['value'];

	Harness::same([$ids['rows']['a'], $ids['rows']['b']], array_column($rows, 'id'), 'wiersze w kolejności');
	Harness::same([0, 1], array_column($rows, 'position'), 'pozycje');

	$byField = collect($rows[0]['values'])->keyBy('field_id');

	Harness::same('Pierwsza wartość', $byField[$uuid('item_title')]['value'], 'pole wiersza');
	Harness::same('Punkt w wierszu', $byField[$uuid('nested')]['value'][0]['values'][0]['value'], 'wiersz zagnieżdżony');
});

Harness::test('Pliki: suma, mime, wymiary, ALT, ścieżka względna, też z wierszy', function () use ($payload) {
	Harness::same(4, count($payload['assets']), 'pliki z pól i wierszy');

	foreach ($payload['assets'] as $asset) {
		Harness::same(1, preg_match('/^[0-9a-f]{64}$/', $asset['sha256']), 'sha256');
		Harness::same('image/png', $asset['mime'], 'mime');
		Harness::same([320, 200], [$asset['width'], $asset['height']], 'wymiary');
		Harness::same("media/{$asset['sha256']}.png", $asset['path'], 'ścieżka');
	}

	Harness::same(1, collect($payload['assets'])->where('alt', 'Pierwsze')->count(), 'ALT');
});

Harness::test('Zakres: bez archiwum, pola ukryte oznaczone, hierarchia', function () use ($payload, $fields, $uuid, $field) {
	Harness::assert(! $fields->has($uuid('old')), 'pole w archiwum');
	Harness::same(true, $field('hidden')['hidden'], 'pole ukryte');
	Harness::assert(! in_array('Stara strona', array_column($payload['pages'], 'name'), true), 'podstrona w archiwum');

	$child = collect($payload['pages'])->firstWhere('name', 'O nas');

	Harness::same($payload['pages'][0]['id'], $child['parent_id'], 'rodzic');
});

Harness::test('C3: dwa eksporty tego samego stanu różnią się tylko czasem', function () use ($project, $exports) {
	Carbon::setTestNow('2026-01-01 10:00:00');
	$first = $exports->build($project);
	Carbon::setTestNow('2026-02-02 11:00:00');
	$second = $exports->build($project);
	Carbon::setTestNow();

	Harness::assert($first['exported_at'] !== $second['exported_at'], 'czas się różni');

	unset($first['exported_at'], $second['exported_at']);

	Harness::same(ExportService::encode($first), ExportService::encode($second), 'treść');
});

Harness::test('C4: raport braków z odwołaniem do podstrony i sekcji', function () use ($payload, $uuid, $home, $ids) {
	$empty = collect($payload['missing']['required_empty']);
	$reason = fn (string $name) => $empty->where('field_id', $uuid($name))->pluck('reason')->all();

	Harness::same(['empty'], $reason('empty'), 'wymagane puste');
	Harness::same(['too_few'], $reason('gallery'), 'za mało zdjęć');
	Harness::same(['missing_alt'], $reason('photo'), 'brak ALT');

	$entry = $empty->firstWhere('field_id', $uuid('empty'));

	Harness::same($home->name, $entry['page'], 'podstrona');
	Harness::same('Główna sekcja', $entry['section'], 'sekcja');
	Harness::same([$ids['rows']['b']], $empty->where('field_id', $uuid('item_title'))->pluck('row_id')->values()->all(), 'wiersz z brakiem');

	$unmapped = collect($payload['missing']['unmapped']);

	Harness::same([$uuid('unmapped')], $unmapped->pluck('field_id')->all(), 'niezmapowane');
	Harness::same('O nas', $unmapped[0]['page'], 'niezmapowane: podstrona');
	Harness::assert(str_contains($unmapped[0]['message'], 'gallery'), 'komunikat');
});

Harness::test('C5: eksport bez tokenu, e-maili, haseł i ścieżek', function () use ($payload, $ids, $admin, $project) {
	$json = ExportService::encode($payload);

	Harness::assert(! str_contains($json, $ids['token']), 'token klienta');
	Harness::assert(! preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+/', $json), 'e-mail');
	Harness::assert(! str_contains($json, '/Users/'), 'ścieżka');
	Harness::assert(! preg_match('/password|haslo|hasło|token|secret|user_pass/i', $json), 'hasło lub token');
	Harness::assert(! str_contains($json, '"' . $admin->user_login . '"'), 'login');
	Harness::assert(! str_contains($json, (string) $project->client_name), 'nazwa klienta');
	Harness::assert(! str_contains($json, (string) ABSPATH), 'ABSPATH');
});

Harness::test('Panel: zapis mapowania, nadpisanie widoczne w eksporcie', function () use ($project, $home, $admin, $mapping, $exports, $ids) {
	DB::beginTransaction();

	try {
		$mapping->save($home, [
			'page' => ['post_type' => 'offer', 'slug' => 'oferta', 'template' => 'template-custom.blade.php'],
			'sections' => [$ids['sections']['hero'] => ['block' => 'acf/hero']],
			'fields' => [$ids['fields']['title'] => ['name' => 'header', 'type' => 'textarea'], $ids['fields']['lead'] => ['name' => null, 'type' => 'textarea']],
		], $admin);

		$payload = $exports->build($project);
		$page = collect($payload['pages'])->firstWhere('id', $home->uuid);
		$title = collect($payload['fields'])->firstWhere('id', Field::query()->findOrFail($ids['fields']['title'])->uuid);

		Harness::same(['post_type' => 'offer', 'slug' => 'oferta', 'template' => 'template-custom.blade.php'], $page['mapping'], 'podstrona');
		Harness::same('acf/hero', collect($payload['sections'])->firstWhere('id', Section::query()->findOrFail($ids['sections']['hero'])->uuid)['mapping']['acf_block'], 'blok');
		Harness::same(['header', 'textarea', true], [$title['acf']['name'], $title['acf']['type'], $title['acf']['mapped']], 'pole');
		Harness::same(null, WpMapping::query()->where('mappable_type', 'field')->where('mappable_id', $ids['fields']['lead'])->first()->settings, 'typ domyślny nie jest nadpisaniem');
		Harness::same([], app(ExportValidator::class)->validate($payload), 'nadal poprawny');
	} finally {
		DB::rollBack();
	}
});

Harness::test('Panel: niezgodny typ, duplikat nazwy i obce pole odrzucone', function () use ($home, $admin, $mapping, $ids) {
	$page = ['post_type' => 'page', 'slug' => '', 'template' => null];
	$rejected = function (array $data, string $message) use ($home, $admin, $mapping, $page) {
		DB::beginTransaction();

		try {
			Harness::throws(ValidationException::class, fn () => $mapping->save($home, $data + ['page' => $page], $admin), $message);
		} finally {
			DB::rollBack();
		}
	};

	$rejected(['fields' => [$ids['fields']['title'] => ['type' => 'gallery']]], 'tekst jako galeria');
	$rejected(['fields' => [$ids['fields']['photo'] => ['type' => 'text']]], 'zdjęcie jako tekst');
	$rejected(['fields' => [$ids['fields']['title'] => ['name' => 'x'], $ids['fields']['lead'] => ['name' => 'x']]], 'duplikat nazwy');
	$rejected(['fields' => [$ids['fields']['unmapped'] => ['name' => 'x']]], 'pole innej podstrony');
	$rejected(['sections' => [999999 => ['block' => 'acf/x']]], 'obca sekcja');
});

Harness::test('Panel: duplikat wobec zapisanej nazwy spoza formularza odrzucony', function () use ($home, $admin, $mapping, $ids) {
	$page = ['post_type' => 'page', 'slug' => '', 'template' => null];

	DB::beginTransaction();

	try {
		$mapping->save($home, ['page' => $page, 'fields' => [$ids['fields']['title'] => ['name' => 'x']]], $admin);
		Harness::throws(ValidationException::class, fn () => $mapping->save($home, ['page' => $page, 'fields' => [$ids['fields']['lead'] => ['name' => 'x']]], $admin), 'kolizja z zapisanym');
	} finally {
		DB::rollBack();
	}
});

Harness::test('Podstrona pod rodzicem w archiwum staje się korzeniem', function () use ($project, $exports, $ids) {
	DB::beginTransaction();

	try {
		$child = Page::query()->findOrFail($ids['pages']['child']);
		$child->forceFill(['parent_id' => $ids['pages']['retired']])->save();

		$payload = $exports->build($project);

		Harness::same(null, collect($payload['pages'])->firstWhere('id', $child->uuid)['parent_id'], 'rodzic');
		Harness::same([], app(ExportValidator::class)->validate($payload), 'poprawny');
	} finally {
		DB::rollBack();
	}
});

exit(Harness::summary('ETAP 11 — logika'));
