<?php

/**
 * Etap 06 — trwałe dane testów HTTP na projektach z tests/stage03/setup.php: po dwie sekcje i komplet pól
 * w podstronie A oraz sekcja z polem w podstronie B (cel prób IDOR). Znikają kaskadowo z projektem testowym.
 */

use App\Models\Page;
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
$pageA = Page::query()->findOrFail((int) $manifest['pages']['a']);
$pageB = Page::query()->findOrFail((int) $manifest['pages']['b']);

$first = $service->create($pageA, ['name' => 'Sekcja pierwsza'], $admin);
$second = $service->create($pageA, ['name' => 'Sekcja druga'], $admin);
$text = $service->createField($first, ['type' => 'text', 'label' => 'Nagłówek'], $admin);
$other = $service->createField($first, ['type' => 'textarea', 'label' => 'Opis'], $admin);
$repeater = $service->createField($first, ['type' => 'repeater', 'label' => 'Karty'], $admin);
$nested = $service->createField($first, ['type' => 'text', 'label' => 'Tytuł karty', 'parent_id' => $repeater->id], $admin);
$withContent = $service->createField($first, ['type' => 'text', 'label' => 'Z treścią'], $admin);
app(ContentService::class)->save($withContent, 'Treść testowa', 0, Actor::user($admin));

$foreignSection = $service->create($pageB, ['name' => 'Sekcja obca'], $admin);
$foreignField = $service->createField($foreignSection, ['type' => 'text', 'label' => 'Pole obce'], $admin);

file_put_contents('/tmp/fmi-stage06-ids.json', json_encode([
	'first' => $first->id,
	'second' => $second->id,
	'text' => $text->id,
	'other' => $other->id,
	'repeater' => $repeater->id,
	'nested' => $nested->id,
	'with_content' => $withContent->id,
	'foreign_section' => $foreignSection->id,
	'foreign_field' => $foreignField->id,
]));

fwrite(STDOUT, "Dane etapu 06 zasiane.\n");
