<?php

/**
 * Etap 03 — testy logiki: hierarchia, slugi, cykle, duplikowanie, postęp, cykl życia projektu.
 * Całość działa w transakcji wycofywanej na końcu, więc baza zostaje nietknięta.
 */

require __DIR__ . '/_harness.php';

use App\Enums\ElementSource;
use App\Enums\FieldType;
use App\Enums\ProjectStatus;
use App\Exceptions\PageStructureException;
use App\Integrations\Figma\FigmaUrl;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Field;
use App\Models\FieldValue;
use App\Models\Page;
use App\Models\Project;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Services\PageDuplicationService;
use App\Services\PageService;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;

$admin = get_users(['role' => 'administrator', 'number' => 1]);
$actor = $admin[0] ?? null;

if (! $actor) {
	fwrite(STDERR, "BLOCKED: brak użytkownika administratora do wykonania testów.\n");
	exit(2);
}

wp_set_current_user($actor->ID);

/** @var PageService $pages */
$pages = app(PageService::class);
/** @var PageDuplicationService $duplication */
$duplication = app(PageDuplicationService::class);
/** @var ProjectService $projects */
$projects = app(ProjectService::class);

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];
$throws = [Harness::class, 'throws'];

DB::beginTransaction();

try {
	$project = $projects->create(['name' => 'TEST etap03 projekt', 'client_name' => 'Klient TEST'], $actor);
	$other = $projects->create(['name' => 'TEST etap03 projekt obcy'], $actor);

	// ---------------------------------------------------------------- C4: frame per podstrona
	$t('C4: FigmaUrl odrzuca link do pliku jako link do frame\'u', function () use ($ok) {
		$ok(! FigmaUrl::isFrame('https://www.figma.com/design/abcdefghij12/Projekt'), 'Link bez node-id nie jest frame\'em');
		$ok(FigmaUrl::isFrame('https://www.figma.com/design/abcdefghij12/Projekt?node-id=12-345'), 'Link z node-id jest frame\'em');
		$ok(! FigmaUrl::isFrame('https://example.com/?node-id=1-2'), 'Obca domena nie jest linkiem Figmy');
	});

	$t('C4: node-id jest normalizowany do postaci kanonicznej', function () use ($same) {
		$same('12:345', FigmaUrl::nodeId('https://www.figma.com/design/abcdefghij12/P?node-id=12-345'), 'Postać z myślnikiem');
		$same('12:345', FigmaUrl::nodeId('https://www.figma.com/design/abcdefghij12/P?node-id=12%3A345'), 'Postać zakodowana');
		$same(null, FigmaUrl::nodeId('https://www.figma.com/design/abcdefghij12/P'), 'Brak parametru');
	});

	$home = $pages->create($project, [
		'name' => 'Strona główna',
		'figma_frame_url' => 'https://www.figma.com/design/abcdefghij12/Projekt?node-id=1-100',
	], $actor);

	$t('C4: link do frame\'u jest zapisany na PODSTRONIE, nie na projekcie', function () use ($ok, $same, $home, $project) {
		$same('https://www.figma.com/design/abcdefghij12/Projekt?node-id=1-100', $home->figma_frame_url, 'URL frame\'u na podstronie');
		$same('1:100', $home->figma_node_id, 'node_id wyliczony z URL podstrony');
		$ok($project->fresh()->figma_file_url === null, 'Projekt nie ma linku frame\'u (ma tylko opcjonalny plik)');
		$ok(\Illuminate\Support\Facades\Schema::hasColumn('fmi_pages', 'figma_frame_url'), 'Kolumna figma_frame_url istnieje w fmi_pages');
	});

	$t('C4: ten sam frame może być użyty przez dwie podstrony', function () use ($pages, $project, $actor, $same) {
		$shared = $pages->create($project, [
			'name' => 'Kopia widoku',
			'figma_frame_url' => 'https://www.figma.com/design/abcdefghij12/Projekt?node-id=1-100',
		], $actor);

		$same('1:100', $shared->figma_node_id, 'Druga podstrona wskazuje ten sam frame');
	});

	// ---------------------------------------------------------------- C2: hierarchia, slugi, cykle
	$t('C2: slug powstaje z nazwy i jest unikalny w obrębie rodzica', function () use ($pages, $project, $actor, $same) {
		$a = $pages->create($project, ['name' => 'O nas'], $actor);
		$b = $pages->create($project, ['name' => 'O nas'], $actor);

		$same('o-nas', $a->slug, 'Pierwszy slug z nazwy');
		$same('o-nas-2', $b->slug, 'Druga podstrona dostaje sufiks');
	});

	$t('C2: ten sam slug jest dozwolony pod różnymi rodzicami', function () use ($pages, $project, $actor, $home, $same) {
		$parentA = $pages->create($project, ['name' => 'Oferta A'], $actor);
		$parentB = $pages->create($project, ['name' => 'Oferta B'], $actor);

		$childA = $pages->create($project, ['name' => 'Szczegóły', 'parent_id' => $parentA->id], $actor);
		$childB = $pages->create($project, ['name' => 'Szczegóły', 'parent_id' => $parentB->id], $actor);

		$same('szczegoly', $childA->slug, 'Dziecko A');
		$same('szczegoly', $childB->slug, 'Dziecko B ma ten sam slug pod innym rodzicem');
	});

	$t('C2: baza blokuje zduplikowany slug w obrębie rodzica (indeks unikalny)', function () use ($project, $throws) {
		$throws(\Illuminate\Database\QueryException::class, function () use ($project) {
			// Celowo obchodzimy serwis, żeby sprawdzić ostatnią linię obrony w schemacie.
			Page::create(['project_id' => $project->id, 'parent_id' => null, 'name' => 'X', 'slug' => 'o-nas']);
		}, 'Indeks unikalny (project_id, parent_key, slug) musi odrzucić duplikat');
	});

	$branch = $pages->create($project, ['name' => 'Gałąź'], $actor);
	$leaf = $pages->create($project, ['name' => 'Liść', 'parent_id' => $branch->id], $actor);

	$t('C2: podstrona nie może być swoim własnym rodzicem', function () use ($pages, $branch, $actor, $throws) {
		$throws(PageStructureException::class, fn () => $pages->update($branch, [
			'name' => $branch->name,
			'parent_id' => $branch->id,
		], $actor), 'Samo-rodzicielstwo musi być odrzucone', 'własną');
	});

	$t('C2: cykl — rodzic nie może trafić pod własne dziecko', function () use ($pages, $branch, $leaf, $actor, $throws) {
		$throws(PageStructureException::class, fn () => $pages->update($branch, [
			'name' => $branch->name,
			'parent_id' => $leaf->id,
		], $actor), 'Przeniesienie rodzica pod potomka musi być odrzucone', 'podrzędnej');
	});

	$t('C2: limit zagnieżdżenia jest pilnowany', function () use ($pages, $project, $actor, $throws) {
		$max = (int) config('fillmein.pages.max_depth', 3);
		$parentId = null;

		for ($i = 0; $i <= $max; $i++) {
			$node = $pages->create($project, ['name' => 'Poziom ' . $i, 'parent_id' => $parentId], $actor);
			$parentId = $node->id;
		}

		$throws(PageStructureException::class, fn () => $pages->create($project, [
			'name' => 'Za głęboko',
			'parent_id' => $parentId,
		], $actor), 'Przekroczenie limitu głębokości musi być odrzucone', 'zagnieżdżenia');
	});

	$t('C2/IDOR: rodzic z innego projektu jest odrzucany', function () use ($pages, $project, $other, $actor, $throws) {
		$foreign = $pages->create($other, ['name' => 'Obca podstrona'], $actor);

		$throws(PageStructureException::class, fn () => $pages->create($project, [
			'name' => 'Próba IDOR',
			'parent_id' => $foreign->id,
		], $actor), 'Rodzic z obcego projektu musi być odrzucony', 'nie należy do tego projektu');
	});

	$t('C2: drzewo odwzorowuje hierarchię', function () use ($project, $ok, $same) {
		$tree = Page::tree(Page::query()->where('project_id', $project->id)->whereNull('archived_at')->orderBy('position')->get());

		$ok($tree !== [], 'Drzewo nie jest puste');

		$flatten = function (array $nodes) use (&$flatten): int {
			$count = 0;

			foreach ($nodes as $node) {
				$count += 1 + $flatten($node['children']);
			}

			return $count;
		};

		$same(
			(int) Page::query()->where('project_id', $project->id)->whereNull('archived_at')->count(),
			$flatten($tree),
			'Drzewo zawiera wszystkie aktywne podstrony dokładnie raz',
		);
	});

	$t('C2: archiwizacja obejmuje poddrzewo, przywrócenie wymaga aktywnego rodzica', function () use ($pages, $branch, $leaf, $actor, $ok, $throws) {
		$pages->archive($branch, $actor);

		$ok($branch->fresh()->isArchived(), 'Rodzic zarchiwizowany');
		$ok($leaf->fresh()->isArchived(), 'Potomek zarchiwizowany kaskadowo');

		$throws(PageStructureException::class, fn () => $pages->restore($leaf->fresh(), $actor),
			'Przywrócenie potomka przy zarchiwizowanym rodzicu musi być odrzucone', 'nadrzędną');

		$pages->restore($branch->fresh(), $actor);
		$ok(! $branch->fresh()->isArchived(), 'Rodzic przywrócony');
	});

	$t('C2: zmiana kolejności odrzuca obce identyfikatory', function () use ($pages, $project, $other, $actor, $throws, $same, $home) {
		$foreign = Page::query()->where('project_id', $other->id)->first();

		$throws(PageStructureException::class, fn () => $pages->reorder($project, null, [$home->id, $foreign->id], $actor),
			'Kolejność z obcym ID musi być odrzucona', 'innego poziomu lub projektu');

		$roots = Page::query()->where('project_id', $project->id)->where('parent_key', 0)->pluck('id')->map(fn ($i) => (int) $i)->all();
		$reversed = array_reverse($roots);
		$pages->reorder($project, null, $reversed, $actor);

		$after = Page::query()->where('project_id', $project->id)->where('parent_key', 0)
			->orderBy('position')->pluck('id')->map(fn ($i) => (int) $i)->all();

		$same($reversed, $after, 'Kolejność zapisana zgodnie z żądaniem');
	});

	echo "\n";

	// ---------------------------------------------------------------- C3: duplikowanie
	// Pełna struktura: sekcja → pole tekstowe + repeater → pole w repeaterze, z treścią i plikiem.
	$src = $pages->create($project, [
		'name' => 'Źródło duplikatu',
		'figma_frame_url' => 'https://www.figma.com/design/abcdefghij12/Projekt?node-id=9-9',
		'client_can_duplicate' => true,
	], $actor);

	$section = Section::create([
		'project_id' => $project->id,
		'page_id' => $src->id,
		'name' => 'Hero',
		'source' => ElementSource::Figma,
		'position' => 0,
	]);

	$heading = Field::create([
		'project_id' => $project->id, 'section_id' => $section->id, 'type' => FieldType::Text,
		'name' => 'heading', 'label' => 'Nagłówek', 'position' => 0, 'settings' => ['max_length' => 90],
	]);

	$photo = Field::create([
		'project_id' => $project->id, 'section_id' => $section->id, 'type' => FieldType::Image,
		'name' => 'photo', 'label' => 'Zdjęcie', 'position' => 1,
	]);

	$repeater = Field::create([
		'project_id' => $project->id, 'section_id' => $section->id, 'type' => FieldType::Repeater,
		'name' => 'items', 'label' => 'Elementy', 'position' => 2,
	]);

	$itemText = Field::create([
		'project_id' => $project->id, 'section_id' => $section->id, 'parent_id' => $repeater->id,
		'type' => FieldType::Text, 'name' => 'title', 'label' => 'Tytuł', 'depth' => 1, 'position' => 0,
	]);

	$asset = Asset::create([
		'project_id' => $project->id, 'field_id' => $photo->id, 'disk' => 'fmi',
		'path' => 'projects/test/asset.jpg', 'original_name' => 'asset.jpg', 'mime_type' => 'image/jpeg',
		'extension' => 'jpg', 'size' => 1234, 'width' => 100, 'height' => 100,
		'checksum' => str_repeat('a', 64), 'uploaded_by_type' => 'user', 'uploaded_by_user_id' => $actor->ID,
	]);

	$row1 = RepeaterRow::create(['project_id' => $project->id, 'field_id' => $repeater->id, 'position' => 0]);
	$row2 = RepeaterRow::create(['project_id' => $project->id, 'field_id' => $repeater->id, 'position' => 1]);

	FieldValue::create(['project_id' => $project->id, 'field_id' => $heading->id, 'value' => 'Witamy', 'version' => 3]);
	$photoValue = FieldValue::create(['project_id' => $project->id, 'field_id' => $photo->id, 'value' => json_encode([$asset->uuid])]);
	$photoValue->assets()->attach($asset->id, ['position' => 0]);
	FieldValue::create(['project_id' => $project->id, 'field_id' => $repeater->id, 'value' => json_encode([$row1->uuid, $row2->uuid])]);
	FieldValue::create(['project_id' => $project->id, 'field_id' => $itemText->id, 'row_id' => $row1->id, 'row_key' => $row1->uuid, 'value' => 'Pierwszy']);
	FieldValue::create(['project_id' => $project->id, 'field_id' => $itemText->id, 'row_id' => $row2->id, 'row_key' => $row2->uuid, 'value' => 'Drugi']);

	$t('C3: duplikat domyślnie kopiuje definicje BEZ treści', function () use ($duplication, $src, $actor, $ok, $same) {
		$copy = $duplication->duplicate($src, [], $actor);

		$same(1, $copy->sections()->count(), 'Sekcje skopiowane');
		$same(4, Field::query()->where('section_id', $copy->sections()->first()->id)->count(), 'Wszystkie 4 pola skopiowane');
		$same(0, FieldValue::query()->whereIn('field_id', Field::query()->where('section_id', $copy->sections()->first()->id)->pluck('id'))->count(),
			'Duplikat nie ma żadnych wartości');
		$ok($copy->id !== $src->id && $copy->uuid !== $src->uuid, 'Duplikat ma nową tożsamość');
		$same($src->id, (int) $copy->duplicated_from_id, 'Zapisano pochodzenie duplikatu');
		$same(ElementSource::Manual, $copy->source, 'Duplikat jest `manual` — import z Figmy go nie dotknie');
		$same($src->figma_frame_url, $copy->figma_frame_url, 'Duplikat zachowuje link do frame\'u');
	});

	$t('C3: duplikat zachowuje strukturę zagnieżdżenia pól i ustawienia', function () use ($duplication, $src, $actor, $ok, $same) {
		$copy = $duplication->duplicate($src, ['name' => 'Kopia struktury'], $actor);
		$sectionCopy = $copy->sections()->first();

		$repeaterCopy = Field::query()->where('section_id', $sectionCopy->id)->where('name', 'items')->first();
		$childCopy = Field::query()->where('section_id', $sectionCopy->id)->where('name', 'title')->first();
		$headingCopy = Field::query()->where('section_id', $sectionCopy->id)->where('name', 'heading')->first();

		$ok($repeaterCopy !== null && $childCopy !== null, 'Repeater i pole potomne istnieją');
		$same((int) $repeaterCopy->id, (int) $childCopy->parent_id, 'parent_id przemapowany na nowy repeater');
		$same(1, (int) $childCopy->depth, 'Głębokość zachowana');
		$same(['max_length' => 90], $headingCopy->settings, 'Ustawienia pola skopiowane');
	});

	$t('C3: duplikat z treścią ma kompletne, PRZEMAPOWANE powiązania', function () use ($duplication, $src, $actor, $asset, $ok, $same) {
		$copy = $duplication->duplicate($src, ['name' => 'Kopia z treścią', 'with_values' => true], $actor);
		$sectionCopy = $copy->sections()->first();
		$fieldIds = Field::query()->where('section_id', $sectionCopy->id)->pluck('id');

		$same(5, FieldValue::query()->whereIn('field_id', $fieldIds)->count(), 'Wszystkie 5 wartości skopiowane');

		$repeaterCopy = Field::query()->where('section_id', $sectionCopy->id)->where('name', 'items')->first();
		$newRows = \App\Models\RepeaterRow::query()->where('field_id', $repeaterCopy->id)->orderBy('position')->get();
		$same(2, $newRows->count(), 'Oba wiersze repeatera skopiowane');

		$listValue = FieldValue::query()->where('field_id', $repeaterCopy->id)->value('value');
		$same($newRows->pluck('uuid')->all(), json_decode($listValue, true), 'Lista UUID wskazuje NOWE wiersze');

		$childCopy = Field::query()->where('section_id', $sectionCopy->id)->where('name', 'title')->first();
		$rowValues = FieldValue::query()->where('field_id', $childCopy->id)->get();
		$ok($rowValues->pluck('row_key')->sort()->values()->all() === $newRows->pluck('uuid')->sort()->values()->all(),
			'row_key wartości wskazuje nowe wiersze');
		$ok($rowValues->every(fn ($v) => in_array((int) $v->row_id, $newRows->pluck('id')->map(fn ($i) => (int) $i)->all(), true)),
			'row_id wartości wskazuje nowe wiersze');

		$photoCopy = Field::query()->where('section_id', $sectionCopy->id)->where('name', 'photo')->first();
		$photoValueCopy = FieldValue::query()->where('field_id', $photoCopy->id)->first();
		$same([(int) $asset->id], $photoValueCopy->assets->pluck('id')->map(fn ($i) => (int) $i)->all(),
			'Plik jest współdzielony przez referencję, nie kopiowany');

		$headingCopy = Field::query()->where('section_id', $sectionCopy->id)->where('name', 'heading')->first();
		$same(0, (int) FieldValue::query()->where('field_id', $headingCopy->id)->value('version'), 'Duplikat startuje z wersją 0');
	});

	$t('C3: wartości duplikatu są NIEZALEŻNE od źródła', function () use ($duplication, $src, $actor, $heading, $same) {
		$copy = $duplication->duplicate($src, ['name' => 'Kopia niezależna', 'with_values' => true], $actor);
		$headingCopy = Field::query()->where('section_id', $copy->sections()->first()->id)->where('name', 'heading')->first();

		FieldValue::query()->where('field_id', $headingCopy->id)->update(['value' => 'ZMIENIONE w duplikacie']);

		$same('Witamy', FieldValue::query()->where('field_id', $heading->id)->value('value'), 'Źródło niezmienione');
		$same('ZMIENIONE w duplikacie', FieldValue::query()->where('field_id', $headingCopy->id)->value('value'), 'Duplikat zmieniony');
	});

	$t('C3: duplikowanie poddrzewa kopiuje podstrony podrzędne', function () use ($duplication, $pages, $project, $actor, $src, $same) {
		$child = $pages->create($project, ['name' => 'Dziecko źródła', 'parent_id' => $src->id], $actor);
		$copy = $duplication->duplicate($src, ['name' => 'Kopia z dziećmi', 'with_children' => true], $actor);

		$same(1, $copy->children()->count(), 'Podstrona podrzędna skopiowana');
		$same('Dziecko źródła', $copy->children()->first()->name, 'Nazwa dziecka zachowana');
	});

	$t('C3: ROLLBACK — błąd w trakcie duplikowania nie zostawia śmieci', function () use ($duplication, $src, $actor, $ok, $same) {
		$before = [
			'pages' => (int) Page::query()->count(),
			'sections' => (int) Section::query()->count(),
			'fields' => (int) Field::query()->count(),
			'values' => (int) FieldValue::query()->count(),
			'rows' => (int) RepeaterRow::query()->count(),
		];

		// Wymuszamy błąd po wstawieniu części wierszy: nasłuch na tworzeniu pola rzuca wyjątek
		// dopiero przy trzecim polu, więc podstrona i sekcja są już w bazie.
		$counter = 0;
		Field::creating(function () use (&$counter) {
			if (++$counter === 3) {
				throw new \RuntimeException('Symulowany błąd w połowie duplikowania');
			}
		});

		try {
			$duplication->duplicate($src, ['name' => 'Kopia, która ma się nie udać', 'with_values' => true], $actor);
			$failed = false;
		} catch (\RuntimeException $e) {
			$failed = str_contains($e->getMessage(), 'Symulowany błąd');
		} finally {
			Field::flushEventListeners();
		}

		$ok($failed, 'Duplikowanie zgłosiło wymuszony błąd');

		$after = [
			'pages' => (int) Page::query()->count(),
			'sections' => (int) Section::query()->count(),
			'fields' => (int) Field::query()->count(),
			'values' => (int) FieldValue::query()->count(),
			'rows' => (int) RepeaterRow::query()->count(),
		];

		$same($before, $after, 'Transakcja wycofała WSZYSTKIE wstawienia nieudanego duplikowania');
	});

	// ---------------------------------------------------------------- Duplikowanie przez klienta
	$t('Klient: duplikowanie wymaga zgody administratora i aktywnego tokenu właściwego projektu', function () use ($duplication, $pages, $project, $other, $src, $actor, $ok, $same, $throws) {
		/** @var \App\Services\ClientAccessService $accessService */
		$accessService = app(\App\Services\ClientAccessService::class);

		$issued = $accessService->generate($project, $actor);
		$access = $issued->access;

		$blocked = $pages->create($project, ['name' => 'Bez zgody klienta'], $actor);
		$throws(PageStructureException::class, fn () => $duplication->duplicateForClient($blocked, $access),
			'Bez client_can_duplicate klient nie może duplikować', 'nie zezwolił');

		$foreignIssued = $accessService->generate($other, $actor);
		$throws(PageStructureException::class, fn () => $duplication->duplicateForClient($src, $foreignIssued->access),
			'Token innego projektu musi być odrzucony', 'nie należy do projektu');

		$copy = $duplication->duplicateForClient($src->fresh(), $access);
		$ok($copy->exists, 'Klient zduplikował dozwoloną podstronę');
		$same(0, FieldValue::query()->whereIn('field_id', Field::query()->where('section_id', $copy->sections()->first()->id)->pluck('id'))->count(),
			'Kopia klienta nie zawiera treści');

		$accessService->revoke($access, $actor);
		$throws(PageStructureException::class, fn () => $duplication->duplicateForClient($src->fresh(), $access->fresh()),
			'Unieważniony token nie pozwala duplikować', 'nieaktywny');
	});

	$t('Klient: dziennik aktywności nie zawiera tokenu', function () use ($project, $ok) {
		$entries = Activity::query()->where('project_id', $project->id)->get();

		$ok($entries->isNotEmpty(), 'Dziennik ma wpisy');

		foreach ($entries as $entry) {
			$json = json_encode($entry->properties ?? []);
			$ok(! str_contains(strtolower($json), 'token'), 'Wpis ' . $entry->event . ' nie zawiera słowa „token”');
		}

		$ok(Activity::query()->where('project_id', $project->id)->where('event', 'page.duplicated_by_client')->exists(),
			'Duplikowanie przez klienta jest zapisane w dzienniku');
	});

	echo "\n";

	// ---------------------------------------------------------------- Postęp i cykl życia projektu
	$t('Postęp liczony z rzeczywistych danych (nie z danych przykładowych)', function () use ($project, $same, $ok) {
		$stats = \App\Support\ProjectProgress::forProjectIds([$project->id])[$project->id];

		$expectedTotal = (int) Field::query()->where('project_id', $project->id)
			->whereNull('archived_at')->where('is_hidden', false)
			->whereNotIn('type', ['group', 'repeater'])->count();

		$same($expectedTotal, $stats['total'], 'Mianownik = aktywne widoczne pola z własną wartością');
		$ok($stats['percent'] >= 0 && $stats['percent'] <= 100, 'Procent w zakresie 0–100');
		$ok($stats['filled'] <= $stats['total'], 'Licznik nie przekracza mianownika');
	});

	$t('Postęp pustego projektu to 0%, bez dzielenia przez zero', function () use ($other, $same) {
		$stats = \App\Support\ProjectProgress::forProjectIds([$other->id])[$other->id];

		$same(0, $stats['total'], 'Brak pól');
		$same(0, $stats['percent'], 'Postęp 0%');
	});

	$t('C1: edycja projektu zapisuje zmiany i dziennik', function () use ($projects, $project, $actor, $same, $ok) {
		$projects->update($project, [
			'name' => 'TEST etap03 projekt — nazwa zmieniona',
			'client_name' => 'Klient po zmianie',
			'figma_url' => 'https://www.figma.com/design/abcdefghij12/Plik',
		], $actor);

		$fresh = $project->fresh();
		$same('TEST etap03 projekt — nazwa zmieniona', $fresh->name, 'Nazwa zapisana');
		$same('abcdefghij12', $fresh->figma_file_key, 'Klucz pliku Figmy wyliczony');
		$ok(Activity::query()->where('project_id', $project->id)->where('event', 'project.updated')->exists(), 'Wpis project.updated');
	});

	$t('C1: zmiana statusu i archiwizacja projektu', function () use ($projects, $project, $actor, $same, $ok) {
		$projects->changeStatus($project, ProjectStatus::Collecting, $actor);
		$same(ProjectStatus::Collecting, $project->fresh()->status, 'Status zmieniony');

		$projects->archive($project, $actor);
		$same(ProjectStatus::Archived, $project->fresh()->status, 'Projekt w archiwum');

		$ok(Activity::query()->where('project_id', $project->id)->where('event', 'project.status_changed')->exists(), 'Wpis project.status_changed');
	});

	$t('C1: usunięcie projektu jest miękkie i unieważnia dostęp klienta', function () use ($projects, $other, $actor, $ok, $same) {
		/** @var \App\Services\ClientAccessService $accessService */
		$accessService = app(\App\Services\ClientAccessService::class);
		$issued = $accessService->generate($other, $actor);

		$ok($accessService->resolve($issued->token) !== null, 'Token działa przed usunięciem projektu');

		$projects->delete($other, $actor);

		$same(null, Project::query()->whereKey($other->id)->first(), 'Projekt zniknął ze zwykłych zapytań');
		$ok(Project::withTrashed()->whereKey($other->id)->exists(), 'Dane projektu zostały w bazie (soft delete)');
		$same(null, $accessService->resolve($issued->token), 'Token przestał działać po usunięciu projektu');
	});

	echo "\n";
	$exit = Harness::summary('Etap 03 — logika');
} catch (\Throwable $e) {
	fwrite(STDERR, "\nPRZERWANO: " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
	$exit = 1;
} finally {
	DB::rollBack();
	fwrite(STDOUT, "Transakcja testowa wycofana (baza bez zmian).\n");
}

exit($exit);
