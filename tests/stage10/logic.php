<?php

/**
 * Etap 10 — diff i synchronizacja z Figmą na danych przykładowych v1 i v2, bez sieci.
 * Wszystkie zapisy działają w transakcji wycofywanej na końcu.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Enums\ElementSource;
use App\Enums\FieldType;
use App\Exceptions\FigmaImportException;
use App\Models\Field;
use App\Models\FieldRevision;
use App\Models\FieldValue;
use App\Models\FigmaFrame;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Services\ContentService;
use App\Services\FigmaFrameService;
use App\Services\FigmaImportService;
use App\Services\PageService;
use App\Services\ProjectService;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;

$admin = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;

if (! $admin) {
	fwrite(STDERR, "BLOCKED: brak użytkownika administratora do wykonania testów.\n");
	exit(2);
}

wp_set_current_user($admin->ID);

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];
$throws = [Harness::class, 'throws'];

$load = fn (string $name) => json_decode((string) file_get_contents(__DIR__ . "/fixtures/{$name}.json"), true);
$prune = function (array $document, int $max = 5000): array {
	$count = 0;

	return FigmaFrameService::prune($document, 8, $count, $max);
};

$service = app(FigmaImportService::class);
$content = app(ContentService::class);
$pages = app(PageService::class);
$projects = app(ProjectService::class);
$actor = Actor::user($admin);

/** Podstrona z własnym frame'em w cache; `set` podmienia drzewo jak po ponownym pobraniu. */
$sandbox = function (string $name, array $document) use ($projects, $pages, $admin, $prune) {
	$project = $projects->create(['name' => 'TEST etap10 ' . $name], $admin);
	$page = $pages->create($project, ['name' => $name, 'figma_frame_url' => 'https://www.figma.com/design/STAGE10key01/Plik?node-id=10-1'], $admin);
	$frame = FigmaFrame::create([
		'project_id' => $project->id, 'file_key' => 'STAGE10key01', 'node_id' => '10:1', 'node_name' => $name, 'node_type' => 'FRAME',
		'file_version' => '1', 'nodes_count' => 60, 'document' => $prune($document), 'fetched_at' => now(),
	]);
	$version = 1;

	return (object) [
		'project' => $project,
		'page' => $page,
		'set' => function (array $tree) use ($frame, &$version) {
			$frame->forceFill(['document' => $tree, 'file_version' => (string) ++$version, 'fetched_at' => now()->addSeconds($version)])->save();
		},
	];
};

$fieldOf = fn ($page, string $node) => Field::query()->whereIn('section_id', Section::query()->where('page_id', $page->id)->select('id'))->where('figma_node_id', $node)->first();
$flat = function (array $plan): array {
	$out = [];
	$walk = function (array $entries) use (&$walk, &$out) {
		foreach ($entries as $entry) {
			$out[$entry['proposal']['node_id'] . '|' . ($entry['proposal']['role'] ?? 'section')] = $entry;
			$walk($entry['children'] ?? $entry['fields']);
		}
	};
	$walk($plan['sections']);

	return $out;
};
$changed = fn (array $plan) => collect($flat($plan))->filter(fn ($e) => $e['action'] !== 'unchanged')->map(fn ($e) => [$e['action'], $e['changes']])->all();
$patchNode = function (array &$node, string $id, callable $change) use (&$patchNode): bool {
	if (($node['id'] ?? null) === $id) {
		$change($node);

		return true;
	}

	foreach ($node['children'] ?? [] as $index => $child) {
		if ($patchNode($node['children'][$index], $id, $change)) {
			return true;
		}
	}

	return false;
};

DB::beginTransaction();

try {
	$v1 = $load('frame-v1');
	$v2 = $load('frame-v2');
	$box = $sandbox('v1 v2', $v1);
	$page = $box->page;
	$service->apply($page, $admin, $service->plan($page)['fingerprint']);

	$text = $fieldOf($page, '10:302');
	$value = $content->save($text, 'Treść od klienta', 0, $actor);
	$value = $content->save($text, 'Treść od klienta, druga wersja', $value->version, $actor);

	$cards = $fieldOf($page, '10:405');
	$cardRows = RepeaterRow::query()->where('field_id', $cards->id)->pluck('id')->all();
	[$row] = $content->addRow($cards, (int) (App\Models\FieldValue::query()->where('field_id', $cards->id)->where('row_key', '')->value('version') ?? 0), $actor);

	$oferta = Section::query()->where('page_id', $page->id)->where('figma_node_id', '10:400')->first();
	$manualSection = Section::create(['project_id' => $box->project->id, 'page_id' => $page->id, 'name' => 'Sekcja ręczna', 'source' => ElementSource::Manual, 'position' => 2]);
	$manualField = Field::create(['project_id' => $box->project->id, 'section_id' => $manualSection->id, 'type' => FieldType::Text, 'name' => 'reczne', 'label' => 'Pole ręczne', 'source' => ElementSource::Manual]);
	$manualSibling = Field::create(['project_id' => $box->project->id, 'section_id' => $oferta->id, 'type' => FieldType::Image, 'name' => 'obok', 'label' => 'Obok', 'source' => ElementSource::Manual, 'position' => 1]);
	$content->save($manualField, 'Wartość ręczna', 0, $actor);

	$snapshot = fn () => [
		Section::query()->whereKey($manualSection->id)->first()->getAttributes(),
		Field::query()->whereKey($manualField->id)->first()->getAttributes(),
		Field::query()->whereKey($manualSibling->id)->first()->getAttributes(),
		FieldValue::query()->where('field_id', $manualField->id)->first()->getAttributes(),
		FieldRevision::query()->whereIn('field_value_id', FieldValue::query()->where('field_id', $manualField->id)->select('id'))->get()->map->getAttributes()->all(),
	];
	$manualBefore = $snapshot();

	$t('Podgląd v1 → v1: 0 zmian', function () use ($service, $page, $changed, $same, $ok) {
		$plan = $service->plan($page);

		$ok(! $plan['has_changes'], 'Bez zmian');
		$same([], $changed($plan), 'Pusta lista');
	});

	$plan = null;

	$t('C1: podgląd v1 → v2 wskazuje dokładnie pięć zmian', function () use ($service, $page, $box, $prune, $v2, $changed, &$plan, $same, $ok) {
		($box->set)($prune($v2));
		$plan = $service->plan($page);
		$actual = $changed($plan);

		$same([
			'10:301|field' => ['update', ['label']],
			'10:306|field' => ['create', []],
			'10:405|container' => ['update', ['items']],
			'10:600|section' => ['update', ['order']],
		], collect($actual)->sortKeys()->all(), 'Zmiany elementów');
		$same(['a:field:'], [substr(collect($plan['archive'])->where('action', 'archive')->pluck('key')->first(), 0, 8)], 'Klucz archiwum');
		$same(['10:302'], collect($plan['archive'])->where('action', 'archive')->map(fn ($item) => $item['model']->figma_node_id)->values()->all(), 'Do archiwum tylko usunięta warstwa');
		$same(['create' => 1, 'update' => 3, 'moved' => 1, 'restore' => 0, 'archive' => 1], array_intersect_key($plan['counts'], array_flip(['create', 'update', 'moved', 'archive', 'restore'])), 'Liczniki');
		$ok($plan['counts']['unchanged'] > 20, 'Reszta bez zmian');
	});

	$t('C1: zastosowanie daje oczekiwany stan', function () use ($service, $page, $admin, $fieldOf, $plan, $same, $ok) {
		$counts = $service->apply($page, $admin, $plan['fingerprint']);

		$same([1, 3, 1, 1], [$counts['create'], $counts['update'], $counts['moved'], $counts['archive']], 'Liczniki operacji');
		$same('Nagłówek główny', $fieldOf($page, '10:301')->label, 'Nowa etykieta');
		$same('Nowy cytat z Figmy', $fieldOf($page, '10:306')->figma_text, 'Nowa warstwa');
		$same(4, count($fieldOf($page, '10:405')->figma_meta['items']), 'Cztery elementy listy w meta');

		$order = Section::query()->where('page_id', $page->id)->where('source', 'figma')->orderBy('position')->pluck('figma_node_id')->all();
		$same(['10:200', '10:300', '10:600', '10:400', '10:500'], $order, 'Kontakt przed Ofertą');
	});

	$t('C2: pole z treścią usunięte w Figmie: archiwum, treść i rewizje zostają', function () use ($text, $same, $ok) {
		$fresh = Field::query()->find($text->id);
		$kept = FieldValue::query()->where('field_id', $text->id)->first();

		$ok($fresh !== null && $fresh->archived_at !== null, 'Pole w archiwum');
		$same('Treść od klienta, druga wersja', $kept->value, 'Treść');
		$same(2, FieldRevision::query()->where('field_value_id', $kept->id)->count(), 'Rewizje');
	});

	$t('Wiersze repeatera klienta nie zmieniły się', function () use ($cards, $cardRows, $row, $same) {
		$same([...$cardRows, $row->id], RepeaterRow::query()->where('field_id', $cards->id)->pluck('id')->all(), 'Ten sam wiersz');
	});

	$t('C4: elementy ręczne i ich treść identyczne (snapshot wszystkich kolumn)', function () use ($snapshot, $manualBefore, $same) {
		$same($manualBefore, $snapshot(), 'Snapshot');
	});

	$t('C5: ponowne zastosowanie tej samej wersji nic nie zmienia', function () use ($service, $page, $admin, $changed, $snapshot, $manualBefore, $same, $ok) {
		$rows = fn () => [
			Section::query()->where('page_id', $page->id)->orderBy('id')->get()->map->getAttributes()->all(),
			Field::query()->where('project_id', $page->project_id)->orderBy('id')->get()->map->getAttributes()->all(),
		];
		// Dawny znacznik czasu: zapis w tej samej sekundzie nie mógłby ukryć niepotrzebnego `save()`.
		Section::query()->where('page_id', $page->id)->where('source', 'figma')->update(['updated_at' => '2020-01-01 00:00:00']);
		Field::query()->where('project_id', $page->project_id)->where('source', 'figma')->update(['updated_at' => '2020-01-01 00:00:00']);

		$before = $rows();
		$plan = $service->plan($page);

		$ok(! $plan['has_changes'], 'Bez zmian');
		$same([], $changed($plan), '0 zmian w podglądzie');
		$same([], $plan['defaults'], 'Nic do wyboru');

		$counts = $service->apply($page, $admin, $plan['fingerprint']);

		$same(0, $counts['create'] + $counts['update'] + $counts['archive'] + $counts['restore'], 'Zero operacji');
		$same($before, $rows(), 'Wszystkie wiersze bez zmian');
		$same($manualBefore, $snapshot(), 'Ręczne bez zmian');
	});

	$t('C6: przestarzały podgląd jest odrzucany', function () use ($service, $page, $admin, $box, $prune, $v1, $fieldOf, $same, $throws) {
		($box->set)($prune($v1));
		$fingerprint = $service->plan($page)['fingerprint'];
		$field = $fieldOf($page, '10:301');
		$field->forceFill(['instructions' => 'Zmiana w międzyczasie'])->save();

		$throws(FigmaImportException::class, fn () => $service->apply($page, $admin, $fingerprint), 'Stary odcisk', 'zmieniła się');
		$same(null, $fieldOf($page, '10:306')?->archived_at, 'Nic nie zastosowano');
		$field->forceFill(['instructions' => null])->save();
	});

	$t('C3: frame przycięty nie archiwizuje niczego, a ostrzega', function () use ($sandbox, $prune, $service, $admin, $v1, $v2, $same, $ok) {
		$cut = $sandbox('przycięty', $v1);
		$service->apply($cut->page, $admin, $service->plan($cut->page)['fingerprint']);
		$active = fn () => Section::query()->where('page_id', $cut->page->id)->whereNull('archived_at')->count() + Field::query()->where('project_id', $cut->project->id)->whereNull('archived_at')->count();
		$before = $active();

		($cut->set)($prune($v2, 12));
		$plan = $service->plan($cut->page);

		$ok($plan['truncated'], 'Flaga truncated');
		$same(0, $plan['counts']['archive'], 'Kategoria archiwum pusta');
		$same([], collect($plan['archive'])->where('action', 'archive')->all(), 'Żadnej pozycji do archiwum');
		$ok(count($plan['archive']) > 0, 'Brakujące elementy zostają jako zachowane');

		$service->apply($cut->page, $admin, $plan['fingerprint']);
		$ok($active() >= $before, 'Nikogo nie zarchiwizowano');
	});

	$t('Warstwa wykluczona we frame\'ie przyciętym też nie trafia do archiwum', function () use ($sandbox, $prune, $service, $admin, $v1, $same) {
		$cut = $sandbox('wykluczenie', $v1);
		$service->apply($cut->page, $admin, $service->plan($cut->page)['fingerprint']);
		$service->setRule($cut->page, '10:301', 'exclude', $admin);

		($cut->set)($prune($v1, 40));

		$plan = $service->plan($cut->page);
		$same(true, $plan['truncated'], 'Przycięte');
		$same(0, $plan['counts']['archive'], 'Bez archiwum');
	});

	$t('Zmiana typu: pole z treścią jest ryzykowne i domyślnie odznaczone', function () use ($sandbox, $prune, $patchNode, $service, $content, $actor, $admin, $v1, $fieldOf, $flat, $same, $ok) {
		$risk = $sandbox('typ', $v1);
		$service->apply($risk->page, $admin, $service->plan($risk->page)['fingerprint']);
		$filled = $fieldOf($risk->page, '10:201');
		$empty = $fieldOf($risk->page, '10:301');
		$content->save($filled, 'Tytuł klienta', 0, $actor);

		$doc = $v1;
		$patchNode($doc, '10:201', fn (array &$node) => $node['characters'] = "Dwie\nlinie");
		$patchNode($doc, '10:301', fn (array &$node) => $node['characters'] = "Też\ndwie");
		($risk->set)($prune($doc));

		$plan = $service->plan($risk->page);
		$entries = $flat($plan);
		$risky = $entries['10:201|field'];

		$same('t:f:10:201', $risky['type_key'], 'Ryzykowne');
		$same(null, $entries['10:301|field']['type_key'], 'Puste pole bez ryzyka');
		$ok(in_array('t:f:10:201', $plan['selectable'], true) && ! in_array('t:f:10:201', $plan['defaults'], true), 'Domyślnie odznaczone');

		$service->apply($risk->page, $admin, $plan['fingerprint']);
		$same(FieldType::Text, $fieldOf($risk->page, '10:201')->type, 'Typ pola z treścią bez zmian');
		$same(FieldType::Textarea, $fieldOf($risk->page, '10:301')->type, 'Puste pole zmieniło typ');
		$same('Tytuł klienta', FieldValue::query()->where('field_id', $filled->id)->value('value'), 'Treść zostaje');

		$plan = $service->plan($risk->page);
		$same(['type'], $flat($plan)['10:201|field']['changes'], 'Zmiana czeka w kolejnym podglądzie');

		$service->apply($risk->page, $admin, $plan['fingerprint'], ['t:f:10:201', 'f:10:201']);
		$fresh = $fieldOf($risk->page, '10:201');
		$cleared = FieldValue::query()->where('field_id', $filled->id)->first();

		$same(FieldType::Textarea, $fresh->type, 'Typ po wyborze');
		$same(null, $cleared->value, 'Treść wyczyszczona');
		$same('Tytuł klienta', FieldRevision::query()->where('field_value_id', $cleared->id)->orderBy('version')->value('value'), 'Treść w rewizji');
		$same(false, $service->plan($risk->page)['has_changes'], 'Potem bez zmian');
	});

	$t('Treść wpisana po podglądzie unieważnia podgląd zmiany typu', function () use ($sandbox, $prune, $patchNode, $service, $content, $actor, $admin, $v1, $fieldOf, $throws) {
		$risk = $sandbox('typ wyścig', $v1);
		$service->apply($risk->page, $admin, $service->plan($risk->page)['fingerprint']);
		$doc = $v1;
		$patchNode($doc, '10:201', fn (array &$node) => $node['characters'] = "Dwie\nlinie");
		($risk->set)($prune($doc));

		$fingerprint = $service->plan($risk->page)['fingerprint'];
		$content->save($fieldOf($risk->page, '10:201'), 'Nowa treść', 0, $actor);

		$throws(FigmaImportException::class, fn () => $service->apply($risk->page, $admin, $fingerprint), 'Odrzucone', 'zmieniła się');
	});

	$t('Wybór zmian: odznaczone czekają w kolejnym podglądzie', function () use ($sandbox, $prune, $service, $admin, $v1, $v2, $fieldOf, $same, $ok, $throws) {
		$pick = $sandbox('wybór', $v1);
		$service->apply($pick->page, $admin, $service->plan($pick->page)['fingerprint']);
		($pick->set)($prune($v2));

		$plan = $service->plan($pick->page);
		$throws(FigmaImportException::class, fn () => $service->apply($pick->page, $admin, $plan['fingerprint'], []), 'Pusty wybór', 'co najmniej');

		$counts = $service->apply($pick->page, $admin, $plan['fingerprint'], ['f:10:301']);
		$same([0, 1, 0], [$counts['create'], $counts['update'], $counts['archive']], 'Tylko wybrana');
		$same('Nagłówek główny', $fieldOf($pick->page, '10:301')->label, 'Wybrana zastosowana');
		$same(null, $fieldOf($pick->page, '10:306'), 'Nowa warstwa nieutworzona');
		$same(null, $fieldOf($pick->page, '10:302')->archived_at, 'Bez archiwizacji');

		$next = $service->plan($pick->page);
		$same([1, 2, 1], [$next['counts']['create'], $next['counts']['update'], $next['counts']['archive']], 'Reszta czeka');
	});

	$t('Etykieta zespołu zostaje, a podgląd nie wraca do tej zmiany', function () use ($sandbox, $prune, $patchNode, $service, $admin, $v1, $fieldOf, $flat, $same, $ok) {
		$team = $sandbox('zespół', $v1);
		$service->apply($team->page, $admin, $service->plan($team->page)['fingerprint']);
		$fieldOf($team->page, '10:301')->forceFill(['label' => 'Etykieta zespołu'])->save();

		$doc = $v1;
		$patchNode($doc, '10:301', fn (array &$node) => $node['name'] = 'Inna nazwa w Figmie');
		$patchNode($doc, '10:302', fn (array &$node) => $node['absoluteBoundingBox']['width'] = 777);
		($team->set)($prune($doc));

		$plan = $service->plan($team->page);
		$entry = $flat($plan)['10:301|field'];

		$same('unchanged', $entry['action'], 'Bez zmiany');
		$ok($entry['label_kept'], 'Informacja o etykiecie zespołu');
		$same('unchanged', $flat($plan)['10:302|field']['action'], 'Sam box nie jest zmianą');
	});

	$t('Stabilne ID: pełna ścieżka instancji i wzorzec repeatera', function () use ($sandbox, $prune, $service, $content, $actor, $admin, $fieldOf, $flat, $same, $ok) {
		$long = 'I30:10;' . str_repeat('77:88;', 30) . '2:1';
		$card = fn (string $id, int $x, string $title) => [
			'id' => $id, 'name' => 'Karta', 'type' => 'INSTANCE', 'componentId' => '5:5',
			'absoluteBoundingBox' => ['x' => $x, 'y' => 100, 'width' => 200, 'height' => 100],
			'children' => [
				['id' => str_starts_with($id, 'I30:10') ? $long : $id . ';2:1', 'name' => 'Tytuł karty', 'type' => 'TEXT', 'characters' => $title, 'absoluteBoundingBox' => ['x' => $x, 'y' => 100, 'width' => 200, 'height' => 20]],
				['id' => $id . ';2:2', 'name' => 'Opis karty', 'type' => 'TEXT', 'characters' => 'Opis ' . $title, 'absoluteBoundingBox' => ['x' => $x, 'y' => 130, 'width' => 200, 'height' => 20]],
			],
		];
		$frame = fn (array $cards) => ['id' => '10:1', 'name' => 'Frame', 'type' => 'FRAME', 'absoluteBoundingBox' => ['x' => 0, 'y' => 0, 'width' => 1440, 'height' => 4000], 'children' => [
			['id' => '30:2', 'name' => 'Karty', 'type' => 'FRAME', 'absoluteBoundingBox' => ['x' => 0, 'y' => 0, 'width' => 1440, 'height' => 300], 'children' => $cards],
		]];
		$a = $card('I30:10', 0, 'A');
		$b = $card('I30:11', 300, 'B');
		$c = $card('I30:12', 600, 'C');
		$first = $card('I30:09', -300, 'Nowa pierwsza');

		$box = $sandbox('instancje', $frame([$a, $b, $c]));
		$service->apply($box->page, $admin, $service->plan($box->page)['fingerprint']);

		$repeater = $fieldOf($box->page, 'I30:10');
		$child = $fieldOf($box->page, $long);
		$ok($repeater !== null && $child !== null, 'Pełne ID zapisane bez obcięcia');
		[$row] = $content->addRow($repeater, (int) (App\Models\FieldValue::query()->where('field_id', $repeater->id)->where('row_key', '')->value('version') ?? 0), $actor);
		$content->save($child, 'Treść karty', 0, $actor, $row);

		($box->set)($prune($frame([$first, $a, $b, $c])));
		$plan = $service->plan($box->page);
		$same(['items'], $flat($plan)['I30:10|container']['changes'], 'Nowy pierwszy element nie zmienia wzorca');
		$same(0, $plan['counts']['create'], 'Bez nowych pól');
		$service->apply($box->page, $admin, $plan['fingerprint']);
		$same($child->id, $fieldOf($box->page, $long)->id, 'To samo pole');

		($box->set)($prune($frame([$first, $b, $c])));
		$plan = $service->plan($box->page);
		$same(0, $plan['counts']['create'] + $plan['counts']['archive'], 'Usunięty wzorzec: bez nowych pól i archiwum');
		$service->apply($box->page, $admin, $plan['fingerprint']);

		$same($repeater->id, Field::query()->where('project_id', $box->project->id)->where('type', 'repeater')->sole()->id, 'Ten sam repeater');
		$moved = Field::query()->whereKey($child->id)->first();
		$same('I30:09;2:1', $moved->figma_node_id, 'Pole przeszło na nowy wzorzec');
		$same('Treść karty', FieldValue::query()->where('field_id', $child->id)->value('value'), 'Treść została');
		$same(false, $service->plan($box->page)['has_changes'], 'Potem bez zmian');
	});

	$t('Kontener z polem ręcznym nie jest przenoszony', function () use ($sandbox, $prune, $service, $admin, $v1, $fieldOf, $flat, $same, $ok) {
		$move = $sandbox('kontener', $v1);
		$service->apply($move->page, $admin, $service->plan($move->page)['fingerprint']);
		$group = Field::query()->where('project_id', $move->project->id)->where('type', 'repeater')->where('figma_node_id', '10:405')->first();
		$child = Field::create(['project_id' => $move->project->id, 'section_id' => $group->section_id, 'parent_id' => $group->id, 'type' => FieldType::Text, 'name' => 'dziecko', 'label' => 'Dziecko', 'source' => ElementSource::Manual, 'depth' => 1]);
		$before = $child->fresh()->getAttributes();

		$doc = $v1;
		$take = null;
		$remove = function (array &$node) use (&$remove, &$take) {
			foreach ($node['children'] ?? [] as $index => $item) {
				if (($item['id'] ?? '') === '10:405') {
					$take = $item;
					array_splice($node['children'], $index, 1);

					return;
				}

				$remove($node['children'][$index]);
			}
		};
		$remove($doc);

		$add = function (array &$node) use (&$add, &$take) {
			foreach ($node['children'] ?? [] as $index => $item) {
				if (($item['id'] ?? '') === '10:300') {
					$node['children'][$index]['children'][] = $take;
				}
			}
		};
		$add($doc);
		($move->set)($prune($doc));

		$plan = $service->plan($move->page);
		$entry = $flat($plan)['10:405|container'];

		$ok($entry['move_blocked'], 'Przeniesienie zablokowane');
		$ok(! in_array('move', $entry['changes'], true), 'Bez zmiany „move”');

		$service->apply($move->page, $admin, $plan['fingerprint']);
		$same($group->section_id, $group->fresh()->section_id, 'Kontener na miejscu');
		$same($before, $child->fresh()->getAttributes(), 'Pole ręczne bez zmian');
	});

	$t('Element sprzed etapu 10 dostaje bazę porównania raz', function () use ($sandbox, $prune, $service, $admin, $v1, $fieldOf, $flat, $same) {
		$old = $sandbox('dawny', $v1);
		$service->apply($old->page, $admin, $service->plan($old->page)['fingerprint']);
		$field = $fieldOf($old->page, '10:301');
		$meta = $field->figma_meta;
		unset($meta['proposed']);
		$field->forceFill(['figma_meta' => $meta])->save();

		$plan = $service->plan($old->page);
		$same(['meta'], $flat($plan)['10:301|field']['changes'], 'Jedna zmiana techniczna');
		$service->apply($old->page, $admin, $plan['fingerprint']);
		$same(false, $service->plan($old->page)['has_changes'], 'Potem bez zmian');
	});
} finally {
	DB::rollBack();
}

exit(Harness::summary('ETAP 10 — diff i synchronizacja'));
