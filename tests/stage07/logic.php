<?php

/**
 * Etap 07 — logika linków klienta i zapisów edytora. Transakcja wycofywana na końcu.
 * Tokeny nie są wypisywane.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Enums\ActorType;
use App\Exceptions\ContentConflictException;
use App\Models\Activity;
use App\Models\ClientAccess;
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

$accesses = app(ClientAccessService::class);
$editor = app(ClientEditorService::class);
$content = app(ContentService::class);
$sections = app(SectionService::class);

DB::beginTransaction();

try {
	$project = app(ProjectService::class)->create(['name' => 'TEST etap07'], $admin);
	$other = app(ProjectService::class)->create(['name' => 'TEST etap07 obcy'], $admin);
	$page = app(PageService::class)->create($project, ['name' => 'Strona'], $admin);
	$otherPage = app(PageService::class)->create($other, ['name' => 'Obca'], $admin);
	$section = $sections->create($page, ['name' => 'Sekcja'], $admin);
	$otherSection = $sections->create($otherPage, ['name' => 'Obca'], $admin);
	$make = fn (array $data, $in = null) => $sections->createField($in ?? $section, $data + ['type' => 'text', 'label' => 'Pole'], $admin);
	$issued = $accesses->generate($project, $admin);
	$access = $issued->access;

	$status = function (callable $fn): ?int {
		try {
			$fn();
		} catch (HttpException $e) {
			return $e->getStatusCode();
		}

		return null;
	};

	$t('Link: w bazie tylko hash SHA-256, token ma 256 bitów i nie jest w atrybutach', function () use ($issued, $accesses, $same, $ok) {
		$row = DB::table('fmi_client_accesses')->where('id', $issued->access->id)->first();

		$same(hash('sha256', $issued->token), $row->token_hash, 'Kolumna token_hash');
		$ok(! str_contains(json_encode($row), $issued->token), 'Wiersz bez tokenu');
		$ok(strlen($issued->token) >= 43, 'Długość tokenu');
		$ok(! str_contains(json_encode($issued->access->toArray()), $row->token_hash), 'Hash ukryty w serializacji');
		$ok(! str_contains(print_r($issued, true), $issued->token), 'Token poza zrzutem obiektu');
		$same((int) $issued->access->id, (int) $accesses->resolve($issued->token)->id, 'Rozwiązanie tokenu');
	});

	$t('Link: złe, unieważnione i wygasłe tokeny dają null', function () use ($project, $accesses, $admin, $same) {
		$same(null, $accesses->resolve(str_repeat('a', 43)), 'Nieistniejący');
		$same(null, $accesses->resolve('krotki'), 'Zły format');

		$revoked = $accesses->generate($project, $admin);
		$accesses->revoke($revoked->access, $admin);
		$same(null, $accesses->resolve($revoked->token), 'Unieważniony');

		$expired = $accesses->generate($project, $admin, now()->subMinute());
		$same(null, $accesses->resolve($expired->token), 'Wygasły');
	});

	$t('Link: regeneracja unieważnia aktywne i wydaje nowy; statusy na liście', function () use ($project, $accesses, $admin, $same, $ok) {
		$old = $accesses->generate($project, $admin);
		$new = $accesses->regenerate($project, $admin, now()->addDays(7), 'Nowy');

		$same(null, $accesses->resolve($old->token), 'Stary nie działa');
		$ok($accesses->resolve($new->token) !== null, 'Nowy działa');
		$same('Aktywny', $new->access->statusBadge()[0], 'Status aktywny');
		$same('Unieważniony', $old->access->fresh()->statusBadge()[0], 'Status unieważniony');
		$same('Wygasł', (new ClientAccess(['expires_at' => now()->subDay()]))->statusBadge()[0], 'Status wygasły');
		$same(1, $project->clientAccesses()->active()->count(), 'Jeden aktywny');

		// Kolejne testy potrzebują aktywnego linku z początku zestawu.
	});

	$access = $accesses->generate($project, $admin)->access;

	$t('Link: dziennik bez tokenu', function () use ($project, $issued, $ok) {
		$json = Activity::query()->where('project_id', $project->id)->get()->toJson();

		$ok(str_contains($json, 'client_access.generated'), 'Wpis wygenerowania');
		$ok(! str_contains($json, $issued->token), 'Brak tokenu');
	});

	$t('Zapis każdego typu tekstowego: przez ContentService, z rewizją i aktorem klienta', function () use ($make, $editor, $access, $same, $ok) {
		foreach ([['text', 'Tekst'], ['textarea', "Dwa\nwiersze"]] as [$type, $text]) {
			$field = $make(['type' => $type, 'label' => 'Typ ' . $type]);
			$value = $editor->saveValue($access, $field, null, ['value' => $text], 0);

			$same($text, $value->value, "{$type}: wartość");
			$same(1, $value->version, "{$type}: wersja");
			$same(ActorType::Client, $value->updated_by_type, "{$type}: aktor");
			$same((int) $access->id, $value->updated_by_access_id, "{$type}: dostęp");
			$same(1, FieldRevision::query()->where('field_value_id', $value->id)->count(), "{$type}: rewizja");
		}

		$html = $make(['type' => 'wysiwyg', 'label' => 'HTML']);
		$same('<p><strong>Ok</strong></p>alert(1)', $editor->saveValue($access, $html, null, ['value' => '<p onclick="x()"><strong>Ok</strong></p><script>alert(1)</script>'], 0)->value, 'WYSIWYG oczyszczony');

		$link = $make(['type' => 'link', 'label' => 'Link']);
		$value = $editor->saveValue($access, $link, null, ['label' => 'Więcej', 'url' => 'https://example.com/a'], 0);
		$same(['label' => 'Więcej', 'url' => 'https://example.com/a'], json_decode($value->value, true), 'Link jako JSON');
		$same(null, $editor->saveValue($access, $link, null, ['label' => '', 'url' => ''], 1)->value, 'Pusty link czyści wartość');
	});

	$t('Walidacja: limit znaków, adres linku, typy bez zapisu', function () use ($make, $editor, $access, $throws) {
		$limited = $make(['label' => 'Limit', 'settings' => ['max_length' => 5]]);
		$throws(ValidationException::class, fn () => $editor->saveValue($access, $limited, null, ['value' => 'za długi'], 0), 'Limit');

		$html = $make(['type' => 'wysiwyg', 'label' => 'HTML limit', 'settings' => ['max_length' => 5]]);
		$editor->saveValue($access, $html, null, ['value' => '<p><strong>abcde</strong></p>'], 0);

		$link = $make(['type' => 'link', 'label' => 'Link zły']);

		foreach (['javascript:alert(1)', 'data:text/html,x', 'example.com'] as $url) {
			$throws(ValidationException::class, fn () => $editor->saveValue($access, $link, null, ['label' => 'x', 'url' => $url], 0), 'Adres ' . $url);
		}

		foreach (['image', 'gallery', 'group', 'repeater'] as $type) {
			$field = $make(['type' => $type, 'label' => 'Bez zapisu ' . $type]);
			$throws(ValidationException::class, fn () => $editor->saveValue($access, $field, null, ['value' => 'x'], 0), $type);
		}
	});

	$t('Konflikt wersji: nieaktualna wersja bazowa odrzucona, treść bez zmian', function () use ($make, $editor, $access, $same, $throws) {
		$field = $make(['label' => 'Konflikt']);
		$value = $editor->saveValue($access, $field, null, ['value' => 'Pierwsza'], 0);
		$editor->saveValue($access, $field, null, ['value' => 'Druga'], 1);

		$throws(ContentConflictException::class, fn () => $editor->saveValue($access, $field, null, ['value' => 'Stara baza'], 1), 'Konflikt');
		$same('Druga', $value->fresh()->value, 'Treść');
	});

	$t('Widoczność: archiwum, ukrycie i rodzic → 403; inny projekt i nieistniejące → 404', function () use ($make, $editor, $project, $section, $sections, $admin, $otherSection, $status, $same) {
		$archived = $make(['label' => 'Archiwum']);
		$sections->archiveField($archived, $admin);
		$hidden = $make(['label' => 'Ukryte', 'is_hidden' => true]);
		$group = $make(['type' => 'group', 'label' => 'Rodzic']);
		$child = $make(['label' => 'Dziecko', 'parent_id' => $group->id]);
		$sections->archiveField($group, $admin);
		$foreign = $make(['label' => 'Obce'], $otherSection);
		$visible = $make(['label' => 'Widoczne']);

		$same(null, $status(fn () => $editor->visibleField($project, $visible->id)), 'Widoczne');
		$same(403, $status(fn () => $editor->visibleField($project, $archived->id)), 'Archiwum');
		$same(403, $status(fn () => $editor->visibleField($project, $hidden->id)), 'Ukryte');
		$same(403, $status(fn () => $editor->visibleField($project, $child->id)), 'Rodzic w archiwum');
		$same(404, $status(fn () => $editor->visibleField($project, $foreign->id)), 'Inny projekt');
		$same(404, $status(fn () => $editor->visibleField($project, 999999999)), 'Nieistniejące');

		$inner = $sections->create($section->page, ['name' => 'Do ukrycia'], $admin);
		$field = $make(['label' => 'W sekcji'], $inner);
		$sections->update($inner, ['is_hidden' => true], $admin);
		$same(403, $status(fn () => $editor->visibleField($project, $field->id)), 'Sekcja ukryta');
	});

	$t('Repeater: dodawanie, kolejność, usuwanie z wersjonowaniem; wartości wierszy osobne', function () use ($make, $editor, $access, $same, $ok) {
		$repeater = $make(['type' => 'repeater', 'label' => 'Lista']);
		$child = $make(['label' => 'W wierszu', 'parent_id' => $repeater->id]);

		[$first, $value] = $editor->addRow($access, $repeater, null, 0);
		[$second, $value] = $editor->addRow($access, $repeater, null, $value->version);
		$same([$first->uuid, $second->uuid], ContentService::rowList($value), 'Lista po dodaniu');

		$value = $editor->reorderRows($access, $repeater, null, [$second->uuid, $first->uuid], $value->version);
		$same([$second->uuid, $first->uuid], ContentService::rowList($value), 'Lista po sortowaniu');
		$same(1, $first->fresh()->position, 'Pozycja');

		$a = $editor->saveValue($access, $child, $first, ['value' => 'W pierwszym'], 0);
		$b = $editor->saveValue($access, $child, $second, ['value' => 'W drugim'], 0);
		$same($first->uuid, $a->row_key, 'Klucz wiersza');
		$same('W drugim', $b->value, 'Osobna wartość');

		$value = $editor->removeRow($access, $repeater, null, $first->uuid, $value->version);
		$same([$second->uuid], ContentService::rowList($value), 'Lista po usunięciu');
		$ok($first->fresh()->trashed(), 'Wiersz usunięty miękko');
		$same('W pierwszym', $a->fresh()->value, 'Treść wiersza zostaje');
		$same(1, FieldValue::query()->where('field_id', $child->id)->where('row_key', $second->uuid)->count(), 'Wartość drugiego wiersza');
	});

	$t('Repeater: limity min i max, konflikt wersji, zła lista kolejności', function () use ($make, $editor, $access, $same, $throws) {
		$repeater = $make(['type' => 'repeater', 'label' => 'Limity', 'settings' => ['min_items' => 1, 'max_items' => 2]]);
		[$first, $value] = $editor->addRow($access, $repeater, null, 0);

		$throws(ValidationException::class, fn () => $editor->removeRow($access, $repeater, null, $first->uuid, $value->version), 'Poniżej minimum');
		$throws(ContentConflictException::class, fn () => $editor->addRow($access, $repeater, null, 0), 'Stara wersja');

		[$second, $value] = $editor->addRow($access, $repeater, null, $value->version);
		$throws(ValidationException::class, fn () => $editor->addRow($access, $repeater, null, $value->version), 'Powyżej maksimum');
		$throws(ValidationException::class, fn () => $editor->reorderRows($access, $repeater, null, [$first->uuid], $value->version), 'Niepełna lista');
		$same(2, RepeaterRow::query()->where('field_id', $repeater->id)->count(), 'Dwa wiersze');
	});

	$t('Repeater w repeaterze i IDOR wiersza: wiersz musi należeć do repeatera i żywej gałęzi', function () use ($make, $editor, $access, $otherSection, $status, $same, $throws) {
		$outer = $make(['type' => 'repeater', 'label' => 'Zewnętrzny']);
		$inner = $make(['type' => 'repeater', 'label' => 'Wewnętrzny', 'parent_id' => $outer->id]);
		$leaf = $make(['label' => 'Liść', 'parent_id' => $inner->id]);
		$plain = $make(['label' => 'Poza listą']);

		[$outerRow, $outerValue] = $editor->addRow($access, $outer, null, 0);
		[$outerRow2, $outerValue] = $editor->addRow($access, $outer, null, $outerValue->version);

		$context = $editor->contextRow($inner, $outerRow->uuid);
		[$innerRow, $innerValue] = $editor->addRow($access, $inner, $context, 0);
		$same((int) $outerRow->id, (int) $innerRow->parent_row_id, 'Rodzic wiersza');
		$same($innerRow->uuid, $editor->contextRow($leaf, $innerRow->uuid)->uuid, 'Kontekst pola');

		$throws(ValidationException::class, fn () => $editor->contextRow($plain, $outerRow->uuid), 'Pole poza listą z wierszem');
		$throws(ValidationException::class, fn () => $editor->contextRow($leaf, null), 'Pole w liście bez wiersza');
		$same(404, $status(fn () => $editor->contextRow($leaf, $outerRow->uuid)), 'Wiersz innego repeatera');

		$foreign = $make(['type' => 'repeater', 'label' => 'Obcy'], $otherSection);
		$foreignRow = RepeaterRow::query()->create(['project_id' => $foreign->project_id, 'field_id' => $foreign->id, 'position' => 0]);
		$same(404, $status(fn () => $editor->contextRow($leaf, $foreignRow->uuid)), 'Wiersz innego projektu');
		$same(404, $status(fn () => $editor->removeRow($access, $outer, null, $foreignRow->uuid, $outerValue->version)), 'Usunięcie obcego wiersza');

		$same(404, $status(fn () => $editor->removeRow($access, $inner, $editor->contextRow($inner, $outerRow2->uuid), $innerRow->uuid, 0)), 'Wiersz z innej gałęzi');

		$editor->removeRow($access, $outer, null, $outerRow->uuid, $outerValue->version);
		$same(404, $status(fn () => $editor->contextRow($leaf, $innerRow->uuid)), 'Gałąź usunięta');
	});

	$t('Dziennik: wpisy klienta bez treści', function () use ($project, $make, $editor, $access, $same, $ok) {
		$field = $make(['label' => 'Do dziennika']);
		$editor->saveValue($access, $field, null, ['value' => 'Sekretna treść klienta'], 0);
		$editor->saveValue($access, $field, null, ['value' => 'Sekretna treść klienta'], 1);

		$events = Activity::query()->where('project_id', $project->id)->where('actor_type', 'client')->get();

		$same(1, $events->where('subject_id', $field->id)->where('event', 'content.updated')->count(), 'Powtórzony zapis bez nowego wpisu');
		$ok(! str_contains($events->toJson(), 'Sekretna treść'), 'Bez treści');
		$same((int) $access->id, (int) $events->first()->client_access_id, 'Dostęp w dzienniku');
	});

	$t('Struktura klienta: bez archiwum i ukrytych, wiersze w zapisanej kolejności', function () use ($make, $editor, $access, $page, $section, $sections, $admin, $same, $ok) {
		$hidden = $make(['label' => 'Ukryte w strukturze', 'is_hidden' => true]);
		$archived = $make(['label' => 'Archiwum w strukturze']);
		$sections->archiveField($archived, $admin);
		$repeater = $make(['type' => 'repeater', 'label' => 'Kolejność']);
		[$a, $value] = $editor->addRow($access, $repeater, null, 0);
		[$b, $value] = $editor->addRow($access, $repeater, null, $value->version);
		$editor->reorderRows($access, $repeater, null, [$b->uuid, $a->uuid], $value->version);

		$structure = $editor->structure($page);
		$labels = $structure->children($section)->pluck('label');

		$ok($labels->contains('Kolejność'), 'Widoczne pole');
		$ok(! $labels->contains('Ukryte w strukturze') && ! $labels->contains('Archiwum w strukturze'), 'Bez niewidocznych');
		$same([$b->uuid, $a->uuid], $structure->rows($repeater, null)->pluck('uuid')->all(), 'Kolejność wierszy');
	});
} finally {
	DB::rollBack();
}

exit(Harness::summary('ETAP 07 — logika'));
