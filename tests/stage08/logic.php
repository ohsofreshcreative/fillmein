<?php

/**
 * Etap 08 — konflikty, rewizje, przywracanie, walidacja zapisu i postęp. Transakcja wycofywana na końcu.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Enums\ActorType;
use App\Exceptions\ContentConflictException;
use App\Models\FieldRevision;
use App\Models\FieldValue;
use App\Models\RepeaterRow;
use App\Services\ClientAccessService;
use App\Services\ClientEditorService;
use App\Services\ContentService;
use App\Services\PageService;
use App\Services\ProjectService;
use App\Services\SectionService;
use App\Support\Actor;
use App\Support\ProjectProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

$content = app(ContentService::class);
$editor = app(ClientEditorService::class);
$sections = app(SectionService::class);
$pages = app(PageService::class);
$projects = app(ProjectService::class);
$actor = Actor::user($admin);

DB::beginTransaction();

try {
	$project = $projects->create(['name' => 'TEST etap08'], $admin);
	$other = $projects->create(['name' => 'TEST etap08 obcy'], $admin);
	$page = $pages->create($project, ['name' => 'Strona'], $admin);
	$otherPage = $pages->create($other, ['name' => 'Obca'], $admin);
	$section = $sections->create($page, ['name' => 'Sekcja'], $admin);
	$otherSection = $sections->create($otherPage, ['name' => 'Obca'], $admin);
	$make = fn (array $data, $in = null) => $sections->createField($in ?? $section, $data + ['type' => 'text', 'label' => 'Pole'], $admin);
	$access = app(ClientAccessService::class)->generate($project, $admin)->access;
	$client = Actor::client($access);
	$status = function (callable $fn): ?int {
		try {
			$fn();
		} catch (HttpException $e) {
			return $e->getStatusCode();
		}

		return null;
	};

	$t('Konflikt: pierwsza wartość w bazie, druga wraca w wyjątku, żadna nie przepada', function () use ($make, $content, $actor, $client, $same) {
		$field = $make(['label' => 'Konflikt']);
		$first = $content->save($field, 'Pierwsza', 0, $actor);

		try {
			$content->save($field, 'Druga', 0, $client);
			$same('konflikt', 'brak', 'Wyjątek');
		} catch (ContentConflictException $conflict) {
			$same('Pierwsza', $conflict->current->value, 'Aktualna wartość');
			$same(1, $conflict->current->version, 'Aktualna wersja');
			$same('Druga', $conflict->attempted, 'Odrzucona wartość');
		}

		$same('Pierwsza', $first->fresh()->value, 'Treść w bazie');
		$same(1, $first->fresh()->version, 'Wersja bez zmian');

		$kept = $content->save($field, 'Druga', 1, $client);
		$same(2, $kept->version, 'Zachowanie mojej: zapis z aktualną wersją');
	});

	$t('Rewizje: każdy udany zapis to dokładnie jedna, także tej samej osoby tuż po sobie', function () use ($make, $content, $actor, $client, $same) {
		$field = $make(['label' => 'Rewizje']);
		$value = $content->save($field, 'Raz', 0, $actor);
		$value = $content->save($field, 'Dwa', 1, $actor);
		$value = $content->save($field, 'Trzy', 2, $client);

		$revisions = FieldRevision::query()->where('field_value_id', $value->id)->orderBy('version')->get();
		$same([1, 2, 3], $revisions->pluck('version')->all(), 'Wersje');
		$same(['Raz', 'Dwa', 'Trzy'], $revisions->pluck('value')->all(), 'Wartości');
		$same(ActorType::User, $revisions[0]->actor_type, 'Autor pracownik');
		$same((int) $actor->userId, $revisions[0]->actor_user_id, 'ID pracownika');
		$same(ActorType::Client, $revisions[2]->actor_type, 'Autor klient');
		$same($client->clientAccessId, $revisions[2]->actor_access_id, 'ID dostępu');
	});

	$t('Ponowienie identycznego zapisu nie jest konfliktem i nie tworzy rewizji', function () use ($make, $content, $actor, $same) {
		$field = $make(['label' => 'Ponowienie']);
		$value = $content->save($field, 'Raz', 0, $actor);
		$again = $content->save($field, 'Raz', 0, $actor);

		$same(1, $again->version, 'Wersja');
		$same(1, FieldRevision::query()->where('field_value_id', $value->id)->count(), 'Rewizje');
	});

	$t('Przywrócenie: nowa rewizja, stare nietknięte, nieaktualna baza to konflikt', function () use ($make, $content, $actor, $client, $same, $throws) {
		$field = $make(['label' => 'Przywrócenie']);
		$value = $content->save($field, 'Stara', 0, $actor);
		$value = $content->save($field, 'Nowa', 1, $actor);
		$old = $value->revisions()->where('version', 1)->firstOrFail();
		$snapshot = FieldRevision::query()->where('field_value_id', $value->id)->orderBy('id')->get()->map->getAttributes()->all();

		$throws(ContentConflictException::class, fn () => $content->restore($value, $old, 1, $client), 'Stara baza');

		$restored = $content->restore($value->fresh(), $old, 2, $client);
		$same('Stara', $restored->value, 'Wartość');
		$same(3, $restored->version, 'Wersja');

		$all = FieldRevision::query()->where('field_value_id', $value->id)->orderBy('id')->get();
		$same(3, $all->count(), 'Nowa rewizja');
		$same('Stara', $all[2]->value, 'Treść rewizji');
		$same(ActorType::Client, $all[2]->actor_type, 'Autor przywrócenia');
		$same($snapshot, $all->take(2)->map->getAttributes()->values()->all(), 'Starsze rewizje bez zmian');
	});

	$t('Historia jest niemodyfikowalna', function () use ($make, $content, $actor, $throws, $same) {
		$value = $content->save($make(['label' => 'Niemodyfikowalna']), 'Treść', 0, $actor);
		$revision = $value->revisions()->firstOrFail();

		$throws(\LogicException::class, fn () => $revision->update(['value' => 'Zmieniona']), 'Aktualizacja');
		$throws(\LogicException::class, fn () => $revision->delete(), 'Usunięcie');
		$same('Treść', $revision->fresh()->value, 'Treść');
	});

	$t('Rewizja innego pola: odrzucona w serwisie i niedostępna w edytorze', function () use ($make, $content, $editor, $actor, $access, $status, $same, $throws) {
		$a = $content->save($make(['label' => 'A']), 'A', 0, $actor);
		$bField = $make(['label' => 'B']);
		$b = $content->save($bField, 'B', 0, $actor);

		$throws(ValidationException::class, fn () => $content->restore($a, $b->revisions()->first(), 1, $actor), 'Obca rewizja');
		$same(404, $status(fn () => $editor->restore($access, $a->field, null, $b->revisions()->first()->id, 1)), 'Edytor');
		$same(404, $status(fn () => $editor->restore($access, $bField, null, 999999999, 1)), 'Nieistniejąca');
	});

	$t('Historia: tylko pola z wartością prostą; autor w widoku klienta bez nazwiska', function () use ($make, $content, $editor, $actor, $access, $admin, $status, $same) {
		$field = $make(['label' => 'Autor']);
		$value = $content->save($field, 'A', 0, $actor);
		$value = $content->save($field, 'B', 1, Actor::client($access));
		$history = $editor->history($field, null);

		$same(2, $history['revisions']->count(), 'Rewizje');
		$same('B', $history['revisions'][0]->value, 'Najnowsza pierwsza');
		$same('Klient', $editor->author($history['revisions'][0], true), 'Klient');
		$same('Zespół OhSoFresh', $editor->author($history['revisions'][1], false), 'Klient nie widzi nazwiska');
		$same($admin->display_name, $editor->author($history['revisions'][1], true), 'Pracownik widzi nazwisko');
		$same(404, $status(fn () => $editor->history($make(['type' => 'repeater', 'label' => 'Lista']), null)), 'Repeater');
		$same(404, $status(fn () => $editor->history($make(['type' => 'image', 'label' => 'Zdjęcie']), null)), 'Zdjęcie');
	});

	$t('Walidacja: elementy zarchiwizowane, także przez rodzica, sekcję i podstronę', function () use ($make, $content, $sections, $pages, $page, $project, $actor, $admin, $throws, $same) {
		$field = $make(['label' => 'Archiwum']);
		$sections->archiveField($field, $admin);
		$throws(ValidationException::class, fn () => $content->save($field->fresh(), 'x', 0, $actor), 'Pole');

		$group = $make(['type' => 'group', 'label' => 'Grupa']);
		$child = $make(['label' => 'Dziecko', 'parent_id' => $group->id]);
		$sections->archiveField($group, $admin);
		$throws(ValidationException::class, fn () => $content->save($child->fresh(), 'x', 0, $actor), 'Rodzic');

		$archivedSection = $sections->create($page, ['name' => 'Do archiwum'], $admin);
		$inSection = $make(['label' => 'W sekcji'], $archivedSection);
		$sections->archive($archivedSection, $admin);
		$throws(ValidationException::class, fn () => $content->save($inSection->fresh(), 'x', 0, $actor), 'Sekcja');

		$archivedPage = $pages->create($project, ['name' => 'Do archiwum'], $admin);
		$inPageSection = $sections->create($archivedPage, ['name' => 'Sekcja'], $admin);
		$inPage = $make(['label' => 'W podstronie'], $inPageSection);
		$pages->archive($archivedPage, $admin);
		$throws(ValidationException::class, fn () => $content->save($inPage->fresh(), 'x', 0, $actor), 'Podstrona');

		$same(0, FieldValue::query()->whereIn('field_id', [$field->id, $child->id, $inSection->id, $inPage->id])->count(), 'Bez wartości');
	});

	$t('Walidacja: wiersz innego pola, innego projektu, usunięty; brak i nadmiar wiersza', function () use ($make, $content, $actor, $otherSection, $throws) {
		$repeater = $make(['type' => 'repeater', 'label' => 'Lista A']);
		$child = $make(['label' => 'Pole A', 'parent_id' => $repeater->id]);
		$second = $make(['type' => 'repeater', 'label' => 'Lista B']);
		$make(['label' => 'Pole B', 'parent_id' => $second->id]);
		$plain = $make(['label' => 'Poza listą']);
		[$row, $value] = $content->addRow($repeater, 0, $actor);
		[$secondRow] = $content->addRow($second, 0, $actor);
		[$removed, $value] = $content->addRow($repeater, $value->version, $actor);
		$content->removeRow($repeater, $removed, $value->version, $actor);

		$foreign = $make(['type' => 'repeater', 'label' => 'Obca'], $otherSection);
		[$foreignRow] = $content->addRow($foreign, 0, $actor);

		$throws(ValidationException::class, fn () => $content->save($child, 'x', 0, $actor, $secondRow), 'Wiersz innego pola');
		$throws(\InvalidArgumentException::class, fn () => $content->save($child, 'x', 0, $actor, $foreignRow), 'Wiersz innego projektu');
		$throws(ValidationException::class, fn () => $content->save($child, 'x', 0, $actor, $removed->fresh()), 'Wiersz usunięty');
		$throws(ValidationException::class, fn () => $content->save($child, 'x', 0, $actor), 'Pole listy bez wiersza');
		$throws(ValidationException::class, fn () => $content->save($plain, 'x', 0, $actor, $row), 'Pole poza listą z wierszem');
	});

	$t('Walidacja: limity wierszy i repeater w archiwum', function () use ($make, $content, $sections, $actor, $admin, $throws, $same) {
		$repeater = $make(['type' => 'repeater', 'label' => 'Limit', 'settings' => ['min_items' => 1, 'max_items' => 1]]);
		[$row, $value] = $content->addRow($repeater, 0, $actor);

		$throws(ValidationException::class, fn () => $content->addRow($repeater, $value->version, $actor), 'Powyżej max');
		$throws(ValidationException::class, fn () => $content->removeRow($repeater, $row, $value->version, $actor), 'Poniżej min');

		$sections->archiveField($repeater, $admin);
		$throws(ValidationException::class, fn () => $content->addRow($repeater->fresh(), $value->version, $actor), 'Repeater w archiwum');
		$same(1, RepeaterRow::query()->where('field_id', $repeater->id)->count(), 'Jeden wiersz');
	});

	$t('Walidacja: typ wartości zgodny z FieldType', function () use ($make, $content, $actor, $same, $throws) {
		$limited = $make(['label' => 'Limit', 'settings' => ['max_length' => 3]]);
		$throws(ValidationException::class, fn () => $content->save($limited, 'abcd', 0, $actor), 'Limit znaków');

		$link = $make(['type' => 'link', 'label' => 'Link']);
		$throws(ValidationException::class, fn () => $content->save($link, 'zwykły tekst', 0, $actor), 'Link bez JSON');
		$throws(ValidationException::class, fn () => $content->save($link, '{"label":"x"}', 0, $actor), 'Link bez adresu');
		$throws(ValidationException::class, fn () => $content->save($link, '{"label":"x","url":"javascript:alert(1)"}', 0, $actor), 'Link javascript');
		$same(1, $content->save($link, '{"label":"x","url":"https://example.com"}', 0, $actor)->version, 'Link poprawny');

		$throws(\InvalidArgumentException::class, fn () => $content->save($make(['type' => 'image', 'label' => 'Zdjęcie']), '[]', 0, $actor), 'Zdjęcie');
		$throws(\InvalidArgumentException::class, fn () => $content->save($make(['type' => 'repeater', 'label' => 'Lista']), '[]', 0, $actor), 'Repeater');
	});

	$project2 = fn (string $name) => $projects->create(['name' => $name], $admin);
	$tree = function (string $name) use ($project2, $pages, $sections, $admin) {
		$project = $project2($name);
		$page = $pages->create($project, ['name' => 'Strona'], $admin);

		return [$project, $page, $sections->create($page, ['name' => 'Sekcja'], $admin)];
	};
	$stats = fn ($project) => ProjectProgress::forProjectIds([$project->id])[$project->id];

	$t('Postęp: projekt bez pól', function () use ($tree, $stats, $same) {
		[$project] = $tree('TEST postęp pusty');
		$same(['total' => 0, 'filled' => 0, 'percent' => 0, 'required' => false], $stats($project), 'Statystyki');
		$same('Brak pól do uzupełnienia', ProjectProgress::hint($stats($project)), 'Opis');
	});

	$t('Postęp: same pola opcjonalne liczą się wszystkie', function () use ($tree, $make, $stats, $content, $actor, $same) {
		[$project, , $section] = $tree('TEST postęp opcjonalne');
		$a = $make(['label' => 'A'], $section);
		$make(['label' => 'B'], $section);
		$make(['label' => 'C'], $section);
		$make(['type' => 'group', 'label' => 'Grupa bez wartości'], $section);
		$content->save($a, 'x', 0, $actor);

		$same(['total' => 3, 'filled' => 1, 'percent' => 33, 'required' => false], $stats($project), 'Statystyki');
	});

	$t('Postęp: przy polach wymaganych opcjonalne się nie liczą', function () use ($tree, $make, $stats, $content, $actor, $same) {
		[$project, , $section] = $tree('TEST postęp wymagane');
		$required = $make(['label' => 'Wymagane', 'is_required' => true], $section);
		$make(['label' => 'Wymagane 2', 'is_required' => true], $section);
		$optional = $make(['label' => 'Opcjonalne'], $section);
		$content->save($optional, 'x', 0, $actor);
		$same(['total' => 2, 'filled' => 0, 'percent' => 0, 'required' => true], $stats($project), 'Bez wymaganych');

		$content->save($required, 'x', 0, $actor);
		$same(['total' => 2, 'filled' => 1, 'percent' => 50, 'required' => true], $stats($project), 'Połowa');
		$same('1 z 2 wymaganych pól (50%)', ProjectProgress::hint($stats($project)), 'Opis');
	});

	$t('Postęp: repeater z min wierszy i polem wymaganym', function () use ($tree, $make, $stats, $content, $actor, $same) {
		[$project, , $section] = $tree('TEST postęp min');
		$repeater = $make(['type' => 'repeater', 'label' => 'Lista', 'settings' => ['min_items' => 2]], $section);
		$child = $make(['label' => 'Tytuł', 'is_required' => true, 'parent_id' => $repeater->id], $section);
		$same(['total' => 2, 'filled' => 0, 'percent' => 0, 'required' => true], $stats($project), 'Bez wierszy: dwie brakujące jednostki');

		[$first, $value] = $content->addRow($repeater, 0, $actor);
		$content->save($child, 'x', 0, $actor, $first);
		$same(['total' => 2, 'filled' => 1, 'percent' => 50, 'required' => true], $stats($project), 'Jeden wiersz z dwóch');

		[$second, $value] = $content->addRow($repeater, $value->version, $actor);
		$same(['total' => 2, 'filled' => 1, 'percent' => 50, 'required' => true], $stats($project), 'Pusty drugi wiersz');

		$content->save($child, 'y', 0, $actor, $second);
		$same(['total' => 2, 'filled' => 2, 'percent' => 100, 'required' => true], $stats($project), 'Komplet');

		[$third, $value] = $content->addRow($repeater, $value->version, $actor);
		$same(['total' => 3, 'filled' => 2, 'percent' => 66, 'required' => true], $stats($project), 'Wiersz ponad minimum też musi być wypełniony');

		$content->removeRow($repeater, $third, $value->version, $actor);
		$same(['total' => 2, 'filled' => 2, 'percent' => 100, 'required' => true], $stats($project), 'Usunięty wiersz się nie liczy');
	});

	$t('Postęp: repeater z min wierszy bez wymaganych pól liczy same wiersze', function () use ($tree, $make, $stats, $content, $actor, $same) {
		[$project, , $section] = $tree('TEST postęp same wiersze');
		$repeater = $make(['type' => 'repeater', 'label' => 'Lista', 'settings' => ['min_items' => 2]], $section);
		$make(['label' => 'Opcjonalne', 'parent_id' => $repeater->id], $section);
		$same(['total' => 2, 'filled' => 0, 'percent' => 0, 'required' => true], $stats($project), 'Bez wierszy');

		$content->addRow($repeater, 0, $actor);
		$same(['total' => 2, 'filled' => 1, 'percent' => 50, 'required' => true], $stats($project), 'Jeden wiersz');
	});

	$t('Postęp: archiwum i ukrycie pominięte, także przez rodzica, sekcję i podstronę', function () use ($tree, $make, $stats, $content, $sections, $pages, $actor, $admin, $same) {
		[$project, $page, $section] = $tree('TEST postęp archiwum');
		$active = $make(['label' => 'Aktywne', 'is_required' => true], $section);
		$archived = $make(['label' => 'Archiwum', 'is_required' => true], $section);
		$make(['label' => 'Ukryte', 'is_required' => true, 'is_hidden' => true], $section);
		$group = $make(['type' => 'group', 'label' => 'Grupa'], $section);
		$make(['label' => 'W grupie', 'is_required' => true, 'parent_id' => $group->id], $section);
		$hiddenSection = $sections->create($page, ['name' => 'Ukryta', 'is_hidden' => true], $admin);
		$make(['label' => 'W ukrytej', 'is_required' => true], $hiddenSection);
		$archivedPage = $pages->create($project, ['name' => 'Do archiwum'], $admin);
		$archivedPageSection = $sections->create($archivedPage, ['name' => 'Sekcja'], $admin);
		$make(['label' => 'W archiwalnej', 'is_required' => true], $archivedPageSection);

		$content->save($active, 'x', 0, $actor);
		$content->save($archived, 'x', 0, $actor);
		$sections->archiveField($archived, $admin);
		$sections->archiveField($group, $admin);
		$pages->archive($archivedPage, $admin);

		$same(['total' => 1, 'filled' => 1, 'percent' => 100, 'required' => true], $stats($project), 'Tylko aktywne');
	});

	$t('Postęp: sekcje i podstrony sumują się do projektu', function () use ($tree, $make, $content, $sections, $pages, $actor, $admin, $same) {
		[$project, $page, $section] = $tree('TEST postęp poziomy');
		$second = $sections->create($page, ['name' => 'Druga'], $admin);
		$otherPage = $pages->create($project, ['name' => 'Inna'], $admin);
		$third = $sections->create($otherPage, ['name' => 'Trzecia'], $admin);
		$a = $make(['label' => 'A', 'is_required' => true], $section);
		$make(['label' => 'B', 'is_required' => true], $second);
		$make(['label' => 'C', 'is_required' => true], $third);
		$make(['label' => 'D', 'is_required' => true], $third);
		$content->save($a, 'x', 0, $actor);

		$detail = ProjectProgress::forProject($project->id);

		$same(100, $detail['sections'][$section->id]['percent'], 'Sekcja pełna');
		$same(0, $detail['sections'][$second->id]['percent'], 'Sekcja pusta');
		$same([1, 2], [$detail['pages'][$page->id]['filled'], $detail['pages'][$page->id]['total']], 'Podstrona');
		$same([0, 2], [$detail['pages'][$otherPage->id]['filled'], $detail['pages'][$otherPage->id]['total']], 'Druga podstrona');
		$same([1, 4], [$detail['project']['filled'], $detail['project']['total']], 'Projekt');
	});

	$t('Postęp: wsad dwóch projektów nie miesza danych', function () use ($tree, $make, $content, $actor, $same) {
		[$a, , $sectionA] = $tree('TEST wsad A');
		[$b, , $sectionB] = $tree('TEST wsad B');
		$field = $make(['label' => 'A', 'is_required' => true], $sectionA);
		$make(['label' => 'B'], $sectionB);
		$content->save($field, 'x', 0, $actor);

		$result = ProjectProgress::forProjectIds([$a->id, $b->id]);

		$same(['total' => 1, 'filled' => 1, 'percent' => 100, 'required' => true], $result[$a->id], 'A');
		$same(['total' => 1, 'filled' => 0, 'percent' => 0, 'required' => false], $result[$b->id], 'B');
	});
} finally {
	DB::rollBack();
}

exit(Harness::summary('ETAP 08 — logika'));
