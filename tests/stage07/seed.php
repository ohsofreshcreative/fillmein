<?php

/**
 * Etap 07 — trwałe dane testów HTTP na projektach z tests/stage03/setup.php. Znikają kaskadowo z projektem.
 * Tokeny trafiają wyłącznie do pliku /tmp/fmi-stage07-ids.json (0600), nigdy na wyjście.
 */

use App\Models\ClientAccess;
use App\Models\Page;
use App\Models\Project;
use App\Services\ClientAccessService;
use App\Services\ContentService;
use App\Services\PageService;
use App\Services\SectionService;
use App\Support\Actor;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);

if (! is_array($manifest)) {
	fwrite(STDERR, "BLOCKED: brak manifestu danych testowych etapu 03.\n");
	exit(2);
}

$admin = get_userdata((int) $manifest['users']['admin']);
$service = app(SectionService::class);
$accesses = app(ClientAccessService::class);
$content = app(ContentService::class);
$actor = Actor::user($admin);

$projectA = Project::query()->findOrFail((int) $manifest['projects']['a']);
$projectB = Project::query()->findOrFail((int) $manifest['projects']['b']);
$pageA = Page::query()->findOrFail((int) $manifest['pages']['a']);
$pageB = Page::query()->findOrFail((int) $manifest['pages']['b']);

$child = app(PageService::class)->create($projectA, ['name' => 'Podstrona podrzędna', 'parent_id' => $pageA->id], $admin);
$archivedPage = app(PageService::class)->create($projectA, ['name' => 'Podstrona archiwalna'], $admin);

$section = $service->create($pageA, ['name' => 'Sekcja główna'], $admin);
$second = $service->create($pageA, ['name' => 'Sekcja druga'], $admin);
$make = fn ($in, array $data) => $service->createField($in, $data + ['type' => 'text', 'label' => 'Pole'], $admin);

$text = $make($section, ['label' => 'Nagłówek', 'instructions' => 'Maksymalnie kilka słów', 'is_required' => true, 'settings' => ['max_length' => 40, 'placeholder' => 'Wpisz nagłówek']]);
$textarea = $make($section, ['type' => 'textarea', 'label' => 'Opis']);
$wysiwyg = $make($section, ['type' => 'wysiwyg', 'label' => 'Treść sformatowana']);
$link = $make($section, ['type' => 'link', 'label' => 'Przycisk']);
$image = $make($section, ['type' => 'image', 'label' => 'Zdjęcie']);
$gallery = $make($section, ['type' => 'gallery', 'label' => 'Galeria']);
$group = $make($section, ['type' => 'group', 'label' => 'Dane kontaktowe']);
$inGroup = $make($section, ['label' => 'Telefon', 'parent_id' => $group->id]);
$repeater = $make($section, ['type' => 'repeater', 'label' => 'Karty', 'settings' => ['max_items' => 3, 'min_items' => 1]]);
$rowField = $make($section, ['label' => 'Tytuł karty', 'parent_id' => $repeater->id]);
$nested = $make($section, ['type' => 'repeater', 'label' => 'Punkty', 'parent_id' => $repeater->id]);
$nestedField = $make($section, ['label' => 'Punkt', 'parent_id' => $nested->id]);

$hidden = $make($section, ['label' => 'Pole ukryte', 'is_hidden' => true]);
$archived = $make($section, ['label' => 'Pole archiwalne']);
$service->archiveField($archived, $admin);
$underArchived = $make($section, ['type' => 'group', 'label' => 'Grupa do archiwum']);
$childOfArchived = $make($section, ['label' => 'Dziecko archiwum', 'parent_id' => $underArchived->id]);
$service->archiveField($underArchived, $admin);

$archivedSection = $service->create($pageA, ['name' => 'Sekcja archiwalna'], $admin);
$inArchivedSection = $make($archivedSection, ['label' => 'W sekcji archiwalnej']);
$service->archive($archivedSection, $admin);

$hiddenSection = $service->create($pageA, ['name' => 'Sekcja ukryta', 'is_hidden' => true], $admin);
$inHiddenSection = $make($hiddenSection, ['label' => 'W sekcji ukrytej']);

$childSection = $service->create($child, ['name' => 'Sekcja podstrony'], $admin);
$childField = $make($childSection, ['label' => 'Pole podstrony']);

$archivedPageSection = $service->create($archivedPage, ['name' => 'Sekcja strony archiwalnej'], $admin);
$inArchivedPage = $make($archivedPageSection, ['label' => 'W podstronie archiwalnej']);
app(PageService::class)->archive($archivedPage, $admin);

// Wiersz startowy repeatera (min 1), żeby testy miały czego używać.
[$rowA] = $content->addRow($repeater, 0, $actor);

$foreignSection = $service->create($pageB, ['name' => 'Sekcja B'], $admin);
$foreignField = $make($foreignSection, ['label' => 'Pole B']);
$foreignRepeater = $make($foreignSection, ['type' => 'repeater', 'label' => 'Repeater B']);
$foreignRowField = $make($foreignSection, ['label' => 'W repeaterze B', 'parent_id' => $foreignRepeater->id]);
[$foreignRow] = $content->addRow($foreignRepeater, 0, $actor);

$active = $accesses->generate($projectA, $admin);
$activeB = $accesses->generate($projectB, $admin);
$revoked = $accesses->generate($projectA, $admin);
$accesses->revoke($revoked->access, $admin);
$expired = $accesses->generate($projectA, $admin);
ClientAccess::query()->whereKey($expired->access->id)->update(['expires_at' => now()->subHour()]);

$path = '/tmp/fmi-stage07-ids.json';
touch($path);
chmod($path, 0600);
file_put_contents($path, json_encode([
	'tokens' => ['a' => $active->token, 'b' => $activeB->token, 'revoked' => $revoked->token, 'expired' => $expired->token],
	'access_a' => $active->access->id,
	'pages' => ['child' => $child->id, 'archived' => $archivedPage->id],
	'fields' => [
		'text' => $text->id, 'textarea' => $textarea->id, 'wysiwyg' => $wysiwyg->id, 'link' => $link->id, 'image' => $image->id,
		'gallery' => $gallery->id, 'group' => $group->id, 'in_group' => $inGroup->id, 'repeater' => $repeater->id, 'row_field' => $rowField->id,
		'nested' => $nested->id, 'nested_field' => $nestedField->id, 'hidden' => $hidden->id, 'archived' => $archived->id,
		'child_of_archived' => $childOfArchived->id, 'in_archived_section' => $inArchivedSection->id, 'in_hidden_section' => $inHiddenSection->id,
		'in_archived_page' => $inArchivedPage->id, 'child' => $childField->id, 'foreign' => $foreignField->id,
		'foreign_repeater' => $foreignRepeater->id, 'foreign_row_field' => $foreignRowField->id,
	],
	'rows' => ['a' => $rowA->uuid, 'foreign' => $foreignRow->uuid],
]));

fwrite(STDOUT, "Dane etapu 07 zasiane.\n");
