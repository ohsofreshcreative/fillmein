<?php

/**
 * Etap 08 — trwałe dane testów HTTP na projektach z tests/stage03/setup.php. Znikają kaskadowo z projektem.
 * Tokeny trafiają wyłącznie do pliku /tmp/fmi-stage08-ids.json (0600), nigdy na wyjście.
 */

use App\Models\FieldRevision;
use App\Models\Page;
use App\Models\Project;
use App\Services\ClientAccessService;
use App\Services\ContentService;
use App\Services\SectionService;
use App\Support\Actor;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);

if (! is_array($manifest)) {
	fwrite(STDERR, "BLOCKED: brak manifestu danych testowych etapu 03.\n");
	exit(2);
}

$admin = get_userdata((int) $manifest['users']['admin']);
$service = app(SectionService::class);
$content = app(ContentService::class);
$accesses = app(ClientAccessService::class);
$actor = Actor::user($admin);

$projectA = Project::query()->findOrFail((int) $manifest['projects']['a']);
$projectB = Project::query()->findOrFail((int) $manifest['projects']['b']);
$pageA = Page::query()->findOrFail((int) $manifest['pages']['a']);
$pageB = Page::query()->findOrFail((int) $manifest['pages']['b']);

$section = $service->create($pageA, ['name' => 'Treści'], $admin);
$make = fn ($in, array $data) => $service->createField($in, $data + ['type' => 'text', 'label' => 'Pole'], $admin);

$text = $make($section, ['label' => 'Nagłówek', 'is_required' => true]);
$other = $make($section, ['label' => 'Drugie pole', 'is_required' => true]);
$link = $make($section, ['type' => 'link', 'label' => 'Przycisk']);
$wysiwyg = $make($section, ['type' => 'wysiwyg', 'label' => 'Opis']);
$hidden = $make($section, ['label' => 'Ukryte przed klientem', 'is_hidden' => true]);
$archived = $make($section, ['label' => 'Archiwalne']);
$service->archiveField($archived, $admin);
$repeater = $make($section, ['type' => 'repeater', 'label' => 'Karty', 'settings' => ['max_items' => 2]]);
$rowField = $make($section, ['label' => 'Tytuł karty', 'parent_id' => $repeater->id]);
$secondRepeater = $make($section, ['type' => 'repeater', 'label' => 'Punkty']);
$secondField = $make($section, ['label' => 'Punkt', 'parent_id' => $secondRepeater->id]);

$v1 = $content->save($text, 'Pierwsza wersja', 0, $actor);
$v2 = $content->save($text, 'Druga wersja', 1, $actor);
[$row] = $content->addRow($repeater, 0, $actor);
[$secondRow] = $content->addRow($secondRepeater, 0, $actor);
$revisions = FieldRevision::query()->where('field_value_id', $v2->id)->orderBy('version')->pluck('id')->all();

$sectionB = $service->create($pageB, ['name' => 'Treści B'], $admin);
$fieldB = $make($sectionB, ['label' => 'Pole B']);
$valueB = $content->save($fieldB, 'Treść B', 0, $actor);

$active = $accesses->generate($projectA, $admin);
$activeB = $accesses->generate($projectB, $admin);

$path = '/tmp/fmi-stage08-ids.json';
touch($path);
chmod($path, 0600);
file_put_contents($path, json_encode([
	'tokens' => ['a' => $active->token, 'b' => $activeB->token],
	'fields' => [
		'text' => $text->id, 'other' => $other->id, 'link' => $link->id, 'wysiwyg' => $wysiwyg->id, 'hidden' => $hidden->id, 'archived' => $archived->id,
		'repeater' => $repeater->id, 'row_field' => $rowField->id, 'second_repeater' => $secondRepeater->id, 'second_field' => $secondField->id, 'foreign' => $fieldB->id,
	],
	'rows' => ['a' => $row->uuid, 'second' => $secondRow->uuid],
	'revisions' => ['first' => $revisions[0], 'second' => $revisions[1], 'foreign' => $valueB->revisions()->value('id')],
	'section' => $section->id,
]));

fwrite(STDOUT, "Dane etapu 08 zasiane.\n");
