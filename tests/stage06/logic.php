<?php

/**
 * Etap 06 — logika konfiguratora sekcji i pól oraz odporność elementów ręcznych na import z Figmy.
 * Wszystkie zapisy działają w transakcji wycofywanej na końcu — baza zostaje nietknięta.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Enums\ElementSource;
use App\Enums\FieldType;
use App\Exceptions\FieldConfigException;
use App\Models\Activity;
use App\Models\Field;
use App\Models\FieldRevision;
use App\Models\FigmaFrame;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Services\ContentService;
use App\Services\FigmaFrameService;
use App\Services\FigmaImportService;
use App\Services\PageService;
use App\Services\ProjectService;
use App\Services\SectionService;
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

/** @var SectionService $service */
$service = app(SectionService::class);
$actor = Actor::user($admin);

DB::beginTransaction();

try {
	$project = app(ProjectService::class)->create(['name' => 'TEST etap06'], $admin);
	$page = app(PageService::class)->create($project, ['name' => 'Strona testowa'], $admin);
	$otherPage = app(PageService::class)->create($project, ['name' => 'Druga strona'], $admin);

	$section = $service->create($page, ['name' => 'Hero'], $admin);
	$make = fn (array $data, ?Section $in = null) => $service->createField($in ?? $section, $data + ['type' => 'text', 'label' => 'Pole'], $admin);
	$ids = fn (Section $in, ?int $parent = null) => Field::query()->where('section_id', $in->id)->where('parent_id', $parent)->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

	$t('Sekcja: tworzenie (manual), edycja, archiwizacja, przywracanie', function () use ($service, $page, $admin, $same, $ok) {
		$created = $service->create($page, ['name' => 'Nowa', 'is_hidden' => true], $admin);
		$same(ElementSource::Manual, $created->source, 'Źródło');
		$same(true, $created->is_hidden, 'Ukryta');

		$service->update($created, ['name' => 'Zmieniona', 'is_hidden' => false], $admin);
		$same('Zmieniona', $created->fresh()->name, 'Nazwa');
		$same(false, $created->fresh()->is_hidden, 'Widoczna');

		$service->archive($created, $admin);
		$ok($created->fresh()->isArchived(), 'W archiwum');
		$ok(Section::query()->whereKey($created->id)->exists(), 'Wiersz zostaje');

		$service->restore($created, $admin);
		$ok(! $created->fresh()->isArchived(), 'Przywrócona');
	});

	$t('Sekcja: sortowanie; pominięte na koniec; obca sekcja odrzucona', function () use ($service, $page, $otherPage, $admin, $same, $throws) {
		$a = $service->create($page, ['name' => 'A'], $admin);
		$b = $service->create($page, ['name' => 'B'], $admin);
		$service->reorder($page, [$b->id, $a->id], $admin);

		$same([(int) $b->id, (int) $a->id], Section::query()->where('page_id', $page->id)->orderBy('position')->pluck('id')->map(fn ($id) => (int) $id)->take(2)->all(), 'Kolejność');

		$foreign = $service->create($otherPage, ['name' => 'Obca'], $admin);
		$throws(FieldConfigException::class, fn () => $service->reorder($page, [$a->id, $foreign->id], $admin), 'Obca sekcja', 'innej podstrony');
	});

	$t('Pole każdego typu z FieldType: tworzenie, edycja, archiwizacja, przywracanie', function () use ($make, $service, $admin, $same, $ok) {
		foreach (FieldType::cases() as $type) {
			$field = $make(['type' => $type->value, 'label' => 'Typ ' . $type->value, 'instructions' => 'Pomoc', 'is_required' => true]);

			$same($type, $field->type, "{$type->value}: typ");
			$same(ElementSource::Manual, $field->source, "{$type->value}: źródło");
			$same('typ_' . $type->value, $field->name, "{$type->value}: klucz z etykiety");
			$same(true, $field->is_required, "{$type->value}: wymagane");

			$service->updateField($field, ['label' => 'Inna ' . $type->value, 'instructions' => null, 'is_required' => false], $admin);
			$fresh = $field->fresh();
			$same('Inna ' . $type->value, $fresh->label, "{$type->value}: etykieta");
			$same(null, $fresh->instructions, "{$type->value}: podpowiedź");

			$service->archiveField($fresh, $admin);
			$ok($field->fresh()->isArchived(), "{$type->value}: archiwum");
			$service->restoreField($field->fresh(), $admin);
			$ok(! $field->fresh()->isArchived(), "{$type->value}: przywrócone");
		}
	});

	$t('Ustawienia: tylko klucze typu, puste pomijane, limity sprawdzone', function () use ($make, $service, $admin, $same, $throws) {
		$text = $make(['label' => 'Limit', 'settings' => ['max_length' => '120', 'placeholder' => 'Wpisz', 'min_items' => 3, 'min_width' => '']]);
		$same(['max_length' => 120, 'placeholder' => 'Wpisz'], $text->settings, 'Tekst');

		$gallery = $make(['type' => 'gallery', 'label' => 'Galeria', 'settings' => ['min_items' => 1, 'max_items' => 5, 'min_width' => 800, 'max_length' => 9]]);
		$same(['min_items' => 1, 'max_items' => 5, 'min_width' => 800], $gallery->settings, 'Galeria');

		$same(null, $make(['type' => 'link', 'label' => 'Link', 'settings' => ['max_length' => 5]])->settings, 'Link bez ustawień');

		$throws(FieldConfigException::class, fn () => $make(['type' => 'repeater', 'label' => 'Min', 'settings' => ['min_items' => 5, 'max_items' => 2]]), 'min > max', 'nie może być większa');
		$throws(FieldConfigException::class, fn () => $make(['type' => 'repeater', 'label' => 'Max', 'settings' => ['max_items' => 101]]), 'Twardy limit', 'przekraczać');

		$service->updateField($text, ['settings' => ['placeholder' => 'Inny']], $admin);
		$same(['placeholder' => 'Inny'], $text->fresh()->settings, 'Edycja ustawień');
	});

	$t('Klucz: duplikat w tym samym rodzicu odrzucony (także archiwalny), inny rodzic i generowanie dozwolone', function () use ($make, $service, $section, $admin, $same, $throws, $ok) {
		$first = $make(['label' => 'Tytuł', 'name' => 'tytul_unikalny']);
		$throws(FieldConfigException::class, fn () => $make(['label' => 'Duplikat', 'name' => 'tytul_unikalny']), 'Duplikat', 'zajęty');

		$second = $make(['label' => 'Drugi', 'name' => 'drugi_unikalny']);
		$throws(FieldConfigException::class, fn () => $service->updateField($second, ['name' => 'tytul_unikalny'], $admin), 'Duplikat przy edycji', 'zajęty');

		$service->archiveField($first, $admin);
		$throws(FieldConfigException::class, fn () => $make(['label' => 'Po archiwum', 'name' => 'tytul_unikalny']), 'Klucz archiwalny', 'zajęty');

		$group = $make(['type' => 'group', 'label' => 'Grupa kluczy']);
		$same('tytul_unikalny', $make(['label' => 'W grupie', 'name' => 'tytul_unikalny', 'parent_id' => $group->id])->name, 'Inny rodzic');

		$same('tytul', $make(['label' => 'Tytuł'])->name, 'Z etykiety');
		$same('tytul_2', $make(['label' => 'Tytuł'])->name, 'Kolejny z etykiety');

		$other = app(SectionService::class)->create($section->page, ['name' => 'Kolejna'], $admin);
		$same('tytul', $make(['label' => 'Tytuł'], $other)->name, 'Inna sekcja');
	});

	$t('Repeater z zagnieżdżeniem: repeater → grupa → repeater → pole; limit głębokości i kontener na końcu', function () use ($make, $same, $ok, $throws) {
		$outer = $make(['type' => 'repeater', 'label' => 'Karty', 'settings' => ['min_items' => 1, 'max_items' => 6]]);
		$group = $make(['type' => 'group', 'label' => 'Dane', 'parent_id' => $outer->id]);
		$inner = $make(['type' => 'repeater', 'label' => 'Punkty', 'parent_id' => $group->id]);
		$leaf = $make(['label' => 'Punkt', 'parent_id' => $inner->id]);

		$same([0, 1, 2, 3], [$outer->depth, $group->depth, $inner->depth, $leaf->depth], 'Głębokości');
		$same((int) $inner->id, (int) $leaf->closestRepeater()->id, 'Najbliższy repeater');
		$same(['min_items' => 1, 'max_items' => 6], $outer->settings, 'Min i max wierszy');
		$ok(! $leaf->canHaveChildren(), 'Pole tekstowe bez dzieci');

		$throws(FieldConfigException::class, fn () => $make(['type' => 'group', 'label' => 'Za głęboko', 'parent_id' => $inner->id]), 'Kontener na ostatnim poziomie', 'nie można dodać');
		$throws(FieldConfigException::class, fn () => $make(['label' => 'Pod tekstem', 'parent_id' => $leaf->id]), 'Pole pod polem', 'tylko grupa lub repeater');

		// Stan niemożliwy z UI (np. po imporcie): kontener na ostatnim poziomie nie przyjmie dzieci.
		$deep = Field::query()->create($inner->only(['project_id', 'section_id']) + ['parent_id' => $inner->id, 'type' => 'group', 'name' => 'gleboki', 'label' => 'Głęboki', 'source' => 'manual', 'depth' => 3]);
		$throws(FieldConfigException::class, fn () => $make(['label' => 'Poziom 4', 'parent_id' => $deep->id]), 'Limit głębokości', 'limit zagnieżdżenia');
	});

	$t('Typ nieobsługiwany i obce pole nadrzędne: FieldConfigException', function () use ($make, $service, $otherPage, $admin, $throws) {
		$throws(FieldConfigException::class, fn () => $make(['type' => 'video', 'label' => 'Wideo']), 'Typ', 'Nieobsługiwany');

		$foreign = $service->create($otherPage, ['name' => 'Inna sekcja'], $admin);
		$foreignGroup = $make(['type' => 'group', 'label' => 'Obca grupa'], $foreign);
		$throws(FieldConfigException::class, fn () => $make(['label' => 'IDOR', 'parent_id' => $foreignGroup->id]), 'Obca sekcja', 'nie należy');

		$field = $make(['label' => 'Do zmiany']);
		$throws(FieldConfigException::class, fn () => $service->updateField($field, ['type' => 'video'], $admin), 'Zmiana na nieobsługiwany', 'Nieobsługiwany');
	});

	$t('Sortowanie pól: poziom sekcji i dzieci kontenera; obce pole odrzucone', function () use ($make, $service, $section, $ids, $admin, $same, $throws, $otherPage) {
		$target = $service->create($section->page, ['name' => 'Sortowanie'], $admin);
		$a = $make(['label' => 'A'], $target);
		$b = $make(['label' => 'B'], $target);
		$c = $make(['label' => 'C'], $target);
		$service->reorderFields($target, null, [$c->id, $a->id], $admin);
		$same([(int) $c->id, (int) $a->id, (int) $b->id], $ids($target), 'Poziom sekcji, pominięte na końcu');

		$group = $make(['type' => 'group', 'label' => 'Grupa'], $target);
		$x = $make(['label' => 'X', 'parent_id' => $group->id], $target);
		$y = $make(['label' => 'Y', 'parent_id' => $group->id], $target);
		$service->reorderFields($target, $group->id, [$y->id, $x->id], $admin);
		$same([(int) $y->id, (int) $x->id], $ids($target, (int) $group->id), 'Dzieci kontenera');

		$throws(FieldConfigException::class, fn () => $service->reorderFields($target, null, [$x->id], $admin), 'Pole z innego poziomu', 'spoza tego poziomu');

		$foreign = $service->create($otherPage, ['name' => 'Obca'], $admin);
		$foreignField = $make(['label' => 'Obce'], $foreign);
		$throws(FieldConfigException::class, fn () => $service->reorderFields($target, null, [$foreignField->id], $admin), 'Pole z innej podstrony', 'spoza tego poziomu');
		$throws(FieldConfigException::class, fn () => $service->reorderFields($target, $foreignField->id, [$x->id], $admin), 'Obcy rodzic', 'nie należy');
	});

	$t('Archiwizacja kontenera: dzieci zostają; przywrócenie w kolejności rodzic → dziecko; sekcja z archiwum blokuje', function () use ($make, $service, $section, $admin, $ok, $throws) {
		$group = $make(['type' => 'group', 'label' => 'Kontener archiwum']);
		$child = $make(['label' => 'Dziecko', 'parent_id' => $group->id]);

		$service->archiveField($child, $admin);
		$service->archiveField($group, $admin);
		$throws(FieldConfigException::class, fn () => $service->restoreField($child->fresh(), $admin), 'Dziecko przed rodzicem', 'pole nadrzędne');
		$throws(FieldConfigException::class, fn () => $make(['label' => 'Do archiwum', 'parent_id' => $group->id]), 'Dodanie do archiwum', 'archiwum');

		$service->restoreField($group->fresh(), $admin);
		$service->restoreField($child->fresh(), $admin);
		$ok(! $child->fresh()->isArchived(), 'Dziecko przywrócone');

		$archivedSection = $service->create($section->page, ['name' => 'Archiwalna'], $admin);
		$inside = $make(['label' => 'Wewnątrz'], $archivedSection);
		$service->archiveField($inside, $admin);
		$service->archive($archivedSection, $admin);
		$throws(FieldConfigException::class, fn () => $service->restoreField($inside->fresh(), $admin), 'Sekcja w archiwum', 'sekcję');
		$throws(FieldConfigException::class, fn () => $make(['label' => 'Nowe'], $archivedSection->fresh()), 'Nowe pole w archiwum', 'archiwum');
	});

	$t('Zmiana typu z treścią: wymaga potwierdzenia, czyści przez ContentService, zostawia rewizje', function () use ($make, $service, $admin, $actor, $same, $ok) {
		$field = $make(['label' => 'Z treścią']);
		$value = app(ContentService::class)->save($field, 'Tekst klienta', 0, $actor);
		$revisions = FieldRevision::query()->where('field_value_id', $value->id)->count();

		try {
			$service->updateField($field, ['type' => 'image', 'label' => 'Nie zapisuj'], $admin);
			$ok(false, 'Bez potwierdzenia powinno być odrzucone');
		} catch (FieldConfigException $e) {
			$ok($e->requiresConfirmation, 'Flaga potwierdzenia');
		}

		$same(FieldType::Text, $field->fresh()->type, 'Typ bez zmian');
		$same('Z treścią', $field->fresh()->label, 'Etykieta bez zmian');
		$same('Tekst klienta', $value->fresh()->value, 'Treść bez zmian');

		$service->updateField($field->fresh(), ['type' => 'image', 'confirm_type_change' => true], $admin);
		$same(FieldType::Image, $field->fresh()->type, 'Typ zmieniony');
		$same(null, $value->fresh()->value, 'Treść wyczyszczona');
		$ok($value->fresh()->version > $value->version, 'Wersja podbita');
		$ok(FieldRevision::query()->where('field_value_id', $value->id)->count() > $revisions, 'Nowa rewizja');
		$ok(FieldRevision::query()->where('field_value_id', $value->id)->where('value', 'Tekst klienta')->exists(), 'Treść w historii');
	});

	$t('Zmiana typu bez utraty treści nie wymaga potwierdzenia; ustawienia dopasowane do nowego typu', function () use ($make, $service, $admin, $actor, $same) {
		$field = $make(['label' => 'Tekstowe', 'settings' => ['max_length' => 50, 'placeholder' => 'x']]);
		$value = app(ContentService::class)->save($field, 'Treść', 0, $actor);

		$service->updateField($field, ['type' => 'wysiwyg', 'settings' => ['max_length' => 50, 'placeholder' => 'x']], $admin);
		$same(FieldType::Wysiwyg, $field->fresh()->type, 'Typ');
		$same('Treść', $value->fresh()->value, 'Treść zostaje');
		$same(['max_length' => 50], $field->fresh()->settings, 'Bez placeholdera');

		$empty = $make(['label' => 'Puste']);
		$service->updateField($empty, ['type' => 'gallery'], $admin);
		$same(FieldType::Gallery, $empty->fresh()->type, 'Pusta wartość bez potwierdzenia');
	});

	$t('Zmiana typu kontenera z dziećmi i kontener na ostatnim poziomie: odrzucone', function () use ($make, $service, $admin, $same, $throws) {
		$repeater = $make(['type' => 'repeater', 'label' => 'Kontener z dzieckiem']);
		$child = $make(['label' => 'Dziecko', 'parent_id' => $repeater->id]);

		$throws(FieldConfigException::class, fn () => $service->updateField($repeater, ['type' => 'text'], $admin), 'Repeater → tekst', 'pola podrzędne');
		$throws(FieldConfigException::class, fn () => $service->updateField($repeater, ['type' => 'group'], $admin), 'Repeater → grupa', 'pola podrzędne');

		$service->archiveField($child, $admin);
		$throws(FieldConfigException::class, fn () => $service->updateField($repeater, ['type' => 'text'], $admin), 'Dziecko w archiwum też blokuje', 'pola podrzędne');

		$group = $make(['type' => 'group', 'label' => 'G1']);
		$second = $make(['type' => 'group', 'label' => 'G2', 'parent_id' => $group->id]);
		$third = $make(['type' => 'group', 'label' => 'G3', 'parent_id' => $second->id]);
		$leaf = $make(['label' => 'L', 'parent_id' => $third->id]);
		$throws(FieldConfigException::class, fn () => $service->updateField($leaf, ['type' => 'group'], $admin), 'Kontener na końcu', 'nie można dodać');
	});

	$t('Pusty repeater → tekst: wiersze usuwane miękko', function () use ($make, $service, $admin, $same, $ok) {
		$repeater = $make(['type' => 'repeater', 'label' => 'Pusty']);
		$row = RepeaterRow::query()->create(['project_id' => $repeater->project_id, 'field_id' => $repeater->id, 'position' => 0, 'created_by_type' => 'user']);

		$service->updateField($repeater, ['type' => 'text'], $admin);
		$same(FieldType::Text, $repeater->fresh()->type, 'Typ');
		$ok($row->fresh()->trashed(), 'Wiersz w koszu');
	});

	$t('Dziennik: każda operacja zapisuje wpis, bez treści pól', function () use ($project, $make, $service, $section, $admin, $ok) {
		$field = $make(['label' => 'Do dziennika']);
		$service->updateField($field, ['label' => 'Po zmianie'], $admin);
		$service->reorderFields($section, null, [$field->id], $admin);
		$service->archiveField($field, $admin);
		$service->restoreField($field->fresh(), $admin);

		$events = Activity::query()->where('project_id', $project->id)->get();

		foreach (['section.created', 'section.updated', 'section.archived', 'section.restored', 'section.reordered', 'field.created', 'field.updated', 'field.archived', 'field.restored', 'field.reordered'] as $event) {
			$ok($events->where('event', $event)->isNotEmpty(), "Wpis {$event}");
		}

		$ok(! str_contains($events->toJson(), 'Tekst klienta'), 'Treść klienta poza dziennikiem');
	});

	$t('Import Figmy po ręcznych zmianach: snapshot elementów manual bez zmian', function () use ($service, $project, $page, $admin, $ok, $same) {
		$raw = json_decode((string) file_get_contents(__DIR__ . '/../stage05/fixtures/nodes-landing.json'), true)['nodes']['10:1']['document'];
		$count = 0;
		$document = FigmaFrameService::prune($raw, 8, $count, 5000);

		$importPage = app(PageService::class)->create($project, [
			'name' => 'Import',
			'figma_frame_url' => 'https://www.figma.com/design/abcdefghij12/A?node-id=10-1',
		], $admin);

		FigmaFrame::query()->create([
			'project_id' => $project->id,
			'file_key' => 'abcdefghij12',
			'node_id' => $importPage->figma_node_id,
			'node_name' => 'Landing',
			'node_type' => 'FRAME',
			'document' => $document,
			'nodes_count' => $count,
			'fetched_at' => now(),
		]);

		/** @var FigmaImportService $import */
		$import = app(FigmaImportService::class);
		$import->apply($importPage, $admin, $import->plan($importPage)['fingerprint']);

		$figmaSection = Section::query()->where('page_id', $importPage->id)->where('source', 'figma')->orderBy('position')->firstOrFail();
		$figmaGroup = Field::query()->where('section_id', $figmaSection->id)->where('source', 'figma')->first();

		// Ręczne elementy: własna sekcja z repeaterem oraz pole dodane do sekcji z Figmy.
		$manualSection = $service->create($importPage, ['name' => 'Ręczna', 'is_hidden' => true], $admin);
		$manualRepeater = $service->createField($manualSection, ['type' => 'repeater', 'label' => 'Ręczny repeater', 'settings' => ['max_items' => 4]], $admin);
		$service->createField($manualSection, ['type' => 'text', 'label' => 'Podrzędne', 'parent_id' => $manualRepeater->id], $admin);
		$service->createField($figmaSection, ['type' => 'link', 'label' => 'Ręczny link', 'instructions' => 'Dodane ręcznie'], $admin);
		$archivedManual = $service->createField($figmaSection, ['type' => 'text', 'label' => 'Ręczne w archiwum'], $admin);
		$service->archiveField($archivedManual, $admin);

		// Zmiany zespołu w elementach z Figmy, zmiana kolejności i wymuszenie zmian w pliku.
		$service->update($figmaSection, ['name' => 'Nazwa zespołu'], $admin);
		$service->reorder($importPage, [$manualSection->id], $admin);

		$snapshot = function () use ($importPage) {
			$sectionIds = Section::query()->where('page_id', $importPage->id)->pluck('id');

			return [
				Section::query()->where('page_id', $importPage->id)->where('source', 'manual')->orderBy('id')->get()->map->getAttributes()->all(),
				Field::query()->whereIn('section_id', $sectionIds)->where('source', 'manual')->orderBy('id')->get()->map->getAttributes()->all(),
			];
		};

		$before = $snapshot();
		$ok(count($before[0]) === 1 && count($before[1]) === 4, 'Snapshot obejmuje sekcję i 4 pola');

		// Plik się zmienił: tekst i sekcja znikają, więc import ma realne operacje do wykonania.
		$changed = $raw;
		$removed = null;

		foreach ($changed['children'] as $i => $child) {
			if (($child['id'] ?? '') === '10:300') {
				$removed = $i;
			}
		}

		$ok($removed !== null, 'Fixture ma sekcję 10:300');
		array_splice($changed['children'], $removed, 1);
		$count = 0;
		FigmaFrame::query()->where('project_id', $project->id)->where('node_id', $importPage->figma_node_id)->update([
			'document' => json_encode(FigmaFrameService::prune($changed, 8, $count, 5000)),
			'file_version' => '2',
		]);

		$plan = $import->plan($importPage);
		$ok($plan['has_changes'], 'Import ma zmiany do zastosowania');
		$counts = $import->apply($importPage, $admin, $plan['fingerprint']);
		$ok($counts['archive'] > 0, 'Import coś zarchiwizował');

		$after = $snapshot();
		$same($before, $after, 'Atrybuty elementów manual identyczne przed i po');
		$same('Nazwa zespołu', $figmaSection->fresh()->name, 'Nazwa nadana przez zespół zostaje');

		$service->update($manualSection->fresh(), ['name' => 'Ręczna 2'], $admin);
		$import->apply($importPage, $admin, $import->plan($importPage)['fingerprint']);
		$same('Ręczna 2', $manualSection->fresh()->name, 'Kolejny import też nie nadpisuje');
	});

	$t('Sekcja z Figmy znikająca z pliku zostaje, gdy zawiera aktywne pole ręczne', function () use ($service, $project, $admin, $ok) {
		$importPage = app(PageService::class)->create($project, [
			'name' => 'Import 2',
			'figma_frame_url' => 'https://www.figma.com/design/abcdefghij12/A?node-id=10-1',
		], $admin);
		$raw = json_decode((string) file_get_contents(__DIR__ . '/../stage05/fixtures/nodes-landing.json'), true)['nodes']['10:1']['document'];
		$import = app(FigmaImportService::class);

		// Frame jest wspólny dla podstron z tym samym linkiem, a poprzedni test usunął z niego sekcję.
		$count = 0;
		FigmaFrame::query()->where('project_id', $project->id)->where('node_id', $importPage->figma_node_id)->update([
			'document' => json_encode(FigmaFrameService::prune($raw, 8, $count, 5000)),
		]);
		$import->apply($importPage, $admin, $import->plan($importPage)['fingerprint']);

		$section = Section::query()->where('page_id', $importPage->id)->where('figma_node_id', '10:300')->firstOrFail();
		$manual = $service->createField($section, ['type' => 'text', 'label' => 'Ręczne w sekcji Figmy'], $admin);

		foreach ($raw['children'] as $i => $child) {
			if (($child['id'] ?? '') === '10:300') {
				array_splice($raw['children'], $i, 1);
				break;
			}
		}

		$count = 0;
		FigmaFrame::query()->where('project_id', $project->id)->where('node_id', $importPage->figma_node_id)->update([
			'document' => json_encode(FigmaFrameService::prune($raw, 8, $count, 5000)),
			'file_version' => '3',
		]);

		$import->apply($importPage, $admin, $import->plan($importPage)['fingerprint']);

		$ok(! $section->fresh()->isArchived(), 'Sekcja zostaje');
		$ok(! $manual->fresh()->isArchived(), 'Pole ręczne zostaje');
	});
} finally {
	DB::rollBack();
}

exit(Harness::summary('ETAP 06 — logika'));
