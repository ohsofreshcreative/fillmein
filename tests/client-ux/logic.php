<?php

// Nazwy dla klienta i dostęp do podglądów sekcji. Transakcja wycofywana, Figma nie jest wywoływana.

require __DIR__ . '/../stage03/_harness.php';

use App\Enums\ElementSource;
use App\Enums\FieldType;
use App\Models\Field;
use App\Models\FigmaFrame;
use App\Models\FigmaPreview;
use App\Models\Section;
use App\Services\ClientEditorService;
use App\Services\PageService;
use App\Services\ProjectService;
use App\Services\SectionService;
use App\Support\ClientLabels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

$admin = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;

if (! $admin) {
	fwrite(STDERR, "BLOCKED: brak użytkownika administratora do wykonania testów.\n");
	exit(2);
}

wp_set_current_user($admin->ID);

$editor = app(ClientEditorService::class);
$sections = app(SectionService::class);
$file = 'tests/client-ux-' . uniqid() . '.png';

DB::beginTransaction();

try {
	$project = app(ProjectService::class)->create(['name' => 'TEST client-ux'], $admin);
	$other = app(ProjectService::class)->create(['name' => 'TEST client-ux obcy'], $admin);
	$url = 'https://www.figma.com/design/AbCdEf123456/Test?node-id=10-1';
	$page = app(PageService::class)->create($project, ['name' => 'Strona'], $admin);
	$page->update(['figma_frame_url' => $url]);
	$otherPage = app(PageService::class)->create($other, ['name' => 'Obca'], $admin);
	$make = fn ($page, string $name, ?string $node, array $extra = []) => tap(
		$sections->create($page, ['name' => $name], $admin)
	)->update(['source' => ElementSource::Figma, 'figma_node_id' => $node] + $extra);

	$hero = $make($page, 'slider', '10:2');
	$frame = $make($page, 'Frame 312', '10:3');
	$menu = $make($page, 'menu/variant1', '10:4');
	$hidden = $make($page, 'hidden', '10:5', ['is_hidden' => true]);
	$foreign = $make($otherPage, 'slider', '10:2');
	$add = function (Section $section, string $label, string $type = 'text', ?Field $parent = null) use ($sections, $admin) {
		$field = $sections->createField($section, ['type' => $type, 'label' => $label, 'parent_id' => $parent?->id], $admin);
		$field->update(['source' => ElementSource::Figma]);

		return $field;
	};

	$top = $add($hero, 'top', 'group');
	$add($hero, 'Zastosowania rozwiązań Green-Q', 'text', $top);
	$cards = $add($hero, 'cards', 'repeater');
	$structure = $editor->structure($page);

	Harness::test('Nazwy techniczne rozpoznane, prawdziwe bez zmian', function () {
		foreach (['slider', 'top', 'bar', 'menu/variant1', 'Frame 312', 'Group 7', 'Lista: products', 'Item', ''] as $name) {
			Harness::assert(ClientLabels::isTechnical($name), "Techniczna: {$name}");
		}

		foreach (['Nasze produkty', 'Opinie', 'Płyta DKW-N', 'Sprawdź produkt'] as $name) {
			Harness::assert(! ClientLabels::isTechnical($name), "Prawdziwa: {$name}");
		}
	});

	Harness::test('Tytuł sekcji: nagłówek z treści, znana nazwa albo numer', function () use ($hero, $frame, $menu, $structure) {
		Harness::same('Zastosowania rozwiązań Green-Q', ClientLabels::section($hero, $structure), 'Z pierwszego tekstu');
		Harness::same('Sekcja 2', ClientLabels::section($frame, $structure), 'Bez tekstu');
		Harness::same('Menu', ClientLabels::section($menu, $structure), 'Znana nazwa');
	});

	Harness::test('Rola pola ze stopnia pisma, nie tekst z Figmy', function () {
		$make = function (int $id, string $text, float $size, string $type = 'text') {
			$field = new Field(['label' => $text, 'type' => $type, 'source' => ElementSource::Figma, 'figma_text' => $text]);
			$field->id = $id;
			$field->exists = true;
			$field->figma_meta = ['proposed' => ['label' => $text], 'text_style' => ['fontSize' => $size]];

			return $field;
		};

		Harness::same(
			[1 => 'Etykieta', 2 => 'Nagłówek'],
			ClientLabels::siblings(collect([$make(1, 'Akustyka', 12), $make(2, 'Ocieplenie ścian od wewnątrz', 24)])),
			'Karta',
		);
		Harness::same(
			[1 => 'Nagłówek', 2 => 'Opis'],
			ClientLabels::siblings(collect([$make(1, 'Zachowaj ciepło', 52), $make(2, 'Gotowe płyty', 16, 'textarea')])),
			'Hero',
		);
		Harness::same(
			[1 => 'Tekst 1', 2 => 'Tekst 2', 3 => 'Tekst 3'],
			ClientLabels::siblings(collect([$make(1, 'Grubości:', 14), $make(2, '20–100 mm', 14), $make(3, 'Wymiary:', 14)])),
			'Równe stopnie',
		);
		Harness::same('np. Grubości:', ClientLabels::example($make(1, 'Grubości:', 14)), 'Podpowiedź');

		$renamed = $make(9, 'Tytuł karty', 14);
		$renamed->figma_meta = ['proposed' => ['label' => 'Akustyka']];
		Harness::same([9 => 'Tytuł karty'], ClientLabels::siblings(collect([$renamed])), 'Nazwa od zespołu');
	});

	Harness::test('Analizator: osobne bloki to nie lista, zdjęcie i ikona to pola', function () {
		$text = fn (string $id, string $value) => ['id' => $id, 'name' => $value, 'type' => 'TEXT', 'characters' => $value];
		$block = fn (string $id, string $name) => ['id' => $id, 'name' => $name, 'type' => 'FRAME', 'children' => [$text($id . '1', 'Pierwszy ' . $id), $text($id . '2', 'Drugi ' . $id)]];
		$card = fn (string $id) => ['id' => $id, 'name' => '__card', 'type' => 'FRAME', 'layout' => 'VERTICAL', 'children' => [
			['id' => $id . 'i', 'name' => 'ico', 'type' => 'VECTOR', 'box' => ['x' => 0, 'y' => 0, 'width' => 32, 'height' => 32]],
			['id' => $id . 'p', 'name' => 'photo', 'type' => 'RECTANGLE', 'image_fill' => true, 'box' => ['x' => 0, 'y' => 40, 'width' => 300, 'height' => 200]],
			$text($id . 't', 'Karta ' . $id),
		]];
		$result = (new App\Integrations\Figma\FrameAnalyzer([], null, 3))->analyze([
			'id' => '1:1', 'name' => 'Strona', 'type' => 'FRAME', 'children' => [
				['id' => '2:1', 'name' => 'hero', 'type' => 'FRAME', 'children' => [$block('3:1', '__txt'), $block('3:2', '__btns')]],
				['id' => '2:2', 'name' => 'lista', 'type' => 'FRAME', 'children' => [$card('4:1'), $card('4:2'), $card('4:3')]],
			],
		]);
		$types = fn (array $fields) => array_column($fields, 'type');

		Harness::assert(! in_array('repeater', $types($result['sections'][0]['fields']), true), 'Hero bez listy');

		$list = $result['sections'][1]['fields'][0];
		Harness::same('repeater', $list['type'], 'Karty to lista');
		Harness::same(['Ikona', 'Zdjęcie'], array_values(array_filter(array_column($list['children'], 'label'), fn ($label) => $label !== 'Karta 4:1')), 'Pola kart');
	});

	Harness::test('Import: pusta lista dostaje elementy z projektu, ruszona zostaje', function () use ($page, $cards, $admin) {
		$service = app(App\Services\FigmaImportService::class);
		$seed = new ReflectionMethod($service, 'seedRows');
		$actor = App\Support\Actor::user($admin);
		$cards->update(['figma_meta' => ['items' => [['node_id' => 'a'], ['node_id' => 'b'], ['node_id' => 'c']]]]);

		$seed->invoke($service, $page, $actor);
		Harness::same(3, App\Models\RepeaterRow::query()->where('field_id', $cards->id)->count(), 'Trzy elementy');

		$seed->invoke($service, $page, $actor);
		Harness::same(3, App\Models\RepeaterRow::query()->where('field_id', $cards->id)->count(), 'Drugi import nie dokłada');
	});

	Harness::test('Import: przyciski i listy samych przycisków są ukryte przed klientem', function () {
		$service = app(App\Services\FigmaImportService::class);
		$only = new ReflectionMethod($service, 'onlyLinks');
		$link = ['type' => 'link', 'children' => []];
		$text = ['type' => 'text', 'children' => []];

		Harness::assert($only->invoke($service, $link), 'Przycisk');
		Harness::assert($only->invoke($service, ['type' => 'repeater', 'children' => [$link, $link]]), 'Lista przycisków');
		Harness::assert(! $only->invoke($service, ['type' => 'repeater', 'children' => [$text, $link]]), 'Karta z przyciskiem zostaje');
		Harness::assert(! $only->invoke($service, $text), 'Tekst');
	});

	Harness::test('Pole: grupa bez ramki, lista po polsku, ręczna nazwa zostaje', function () use ($top, $cards) {
		Harness::assert(ClientLabels::isPlainGroup($top), 'Grupa techniczna');
		Harness::same('Karty', ClientLabels::field($cards), 'Repeater');

		$manual = new Field(['label' => 'opinie', 'type' => FieldType::Text, 'source' => ElementSource::Manual]);
		Harness::same('opinie', ClientLabels::field($manual), 'Element ręczny');
	});

	$record = FigmaFrame::query()->create([
		'project_id' => $project->id,
		'file_key' => 'AbCdEf123456',
		'node_id' => '10:1',
		'node_name' => 'Test',
	]);
	Storage::disk('fmi')->put($file, 'x');

	foreach (['10:2', '10:5'] as $node) {
		FigmaPreview::query()->create([
			'frame_id' => $record->id,
			'project_id' => $project->id,
			'file_key' => 'AbCdEf123456',
			'node_id' => $node,
			'path' => $file,
			'width' => 100,
			'height' => 50,
		]);
	}

	$status = function (callable $fn): ?int {
		try {
			$fn();
		} catch (HttpException $e) {
			return $e->getStatusCode();
		}

		return null;
	};

	Harness::test('Podgląd tylko dla sekcji, która go ma', function () use ($editor, $page, $structure, $hero, $frame) {
		$previews = $editor->sectionPreviews($page, $structure);

		Harness::assert(isset($previews[$hero->id]), 'Sekcja z podglądem');
		Harness::assert(! isset($previews[$frame->id]), 'Sekcja bez podglądu');
	});

	Harness::test('Podświetlenie pola: prostokąt warstwy względem sekcji', function () use ($editor, $page, $structure, $hero, $record) {
		$field = Field::query()->where('section_id', $hero->id)->where('type', FieldType::Text)->firstOrFail();
		$field->update(['figma_node_id' => '10:3']);
		$record->update(['document' => ['id' => '10:1', 'name' => 'Test', 'type' => 'FRAME', 'box' => ['x' => 0, 'y' => 0, 'width' => 1000, 'height' => 2000], 'children' => [
			['id' => '10:2', 'name' => 'slider', 'type' => 'FRAME', 'box' => ['x' => 0, 'y' => 1000, 'width' => 1000, 'height' => 500], 'children' => [
				['id' => '10:3', 'name' => 'Nagłówek', 'type' => 'TEXT', 'box' => ['x' => 100, 'y' => 1100, 'width' => 400, 'height' => 50]],
			]],
		]]]);

		$rects = $editor->fieldHighlights($page, $structure, $editor->sectionPreviews($page, $structure));

		Harness::same(['x' => 0.1, 'y' => 0.2, 'w' => 0.4, 'h' => 0.1], $rects[$hero->id][$field->id] ?? null, 'Prostokąt 0–1 względem sekcji');
		Harness::same(1, count($rects), 'Tylko sekcje z podglądem i warstwą');
	});

	Harness::test('Zdjęcie z linku: zapis, odczyt, usunięcie, zła wartość', function () use ($editor, $hero, $admin) {
		$image = Field::query()->create(['project_id' => $hero->project_id, 'section_id' => $hero->id, 'label' => 'Zdjęcie testowe', 'name' => 'zdjecie_testowe', 'type' => FieldType::Image, 'source' => ElementSource::Manual, 'position' => 99]);
		$value = $editor->saveMediaLink($admin, $image, null, 'https://stock.example.com/photo/123', 0);

		Harness::same('https://stock.example.com/photo/123', App\Services\ContentService::mediaLink($value), 'Link zapisany');
		Harness::same([], App\Services\ContentService::rowList($value), 'Lista plików bez linku');
		Harness::assert(App\Services\ContentService::hasContent($value->value), 'Pole liczy się jako uzupełnione');

		$cleared = $editor->saveMediaLink($admin, $image, null, '', $value->version);
		Harness::same(null, App\Services\ContentService::mediaLink($cleared), 'Pusty adres usuwa link');

		$rejected = false;
		try {
			$editor->saveMediaLink($admin, $image, null, 'javascript:alert(1)', $cleared->version);
		} catch (Illuminate\Validation\ValidationException) {
			$rejected = true;
		}
		Harness::assert($rejected, 'Adres bez http(s) odrzucony');
	});

	Harness::test('Uwagi sekcji: zapis, konflikt wersji, sekcja ukryta dla klienta', function () use ($editor, $project, $hero, $hidden, $admin, $status) {
		$note = $editor->saveSectionNote($admin, $project, $hero->id, "Dodatkowy tekst\r\nw dwóch liniach", 0);
		Harness::same([1, "Dodatkowy tekst\nw dwóch liniach"], [$note->version, $note->body], 'Zapis i wersja 1');

		$conflict = false;
		try {
			$editor->saveSectionNote($admin, $project, $hero->id, 'Starsza wersja', 0);
		} catch (App\Exceptions\SectionNoteConflictException $e) {
			$conflict = $e->current->version === 1;
		}
		Harness::assert($conflict, 'Stara wersja bazowa → konflikt z bieżącą');

		$access = App\Models\ClientAccess::query()->where('project_id', $project->id)->first() ?? App\Models\ClientAccess::query()->create(['project_id' => $project->id, 'token_hash' => hash('sha256', 'ux-note-test'), 'created_by' => $admin->ID]);
		Harness::same(403, $status(fn () => $editor->saveSectionNote($access, $project, $hidden->id, 'x', 0)), 'Klient: sekcja ukryta → 403');
		Harness::same(404, $status(fn () => $editor->saveSectionNote($access, $project, 999999999, 'x', 0)), 'Obca sekcja → 404');
		Harness::same(1, $editor->sectionNotes($editor->structure($hero->page))->count(), 'Odczyt uwag sekcji podstrony');
	});

	Harness::test('Zakres podglądu: obcy projekt i sekcja ukryta → 404', function () use ($editor, $project, $hero, $foreign, $hidden, $frame, $status) {
		Harness::same('10:2', $editor->sectionPreview($project, $hero->id)->node_id, 'Własna sekcja');
		Harness::same(404, $status(fn () => $editor->sectionPreview($project, $foreign->id)), 'Obcy projekt');
		Harness::same(404, $status(fn () => $editor->sectionPreview($project, $hidden->id)), 'Sekcja ukryta');
		Harness::same(404, $status(fn () => $editor->sectionPreview($project, $frame->id)), 'Brak podglądu');
	});
} finally {
	DB::rollBack();
	Storage::disk('fmi')->delete($file);
}

exit(Harness::summary('EDYTOR KLIENTA — nazwy i podglądy'));
