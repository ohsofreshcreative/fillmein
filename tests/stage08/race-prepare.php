<?php

/**
 * Etap 08 — pola do wyścigu i wspólny punkt startu obu procesów.
 * Rundy `new`: pierwszy zapis pola, bez rekordu wartości. Rundy `existing`: pole z zapisaną wersją 1.
 */

use App\Models\Page;
use App\Services\ContentService;
use App\Services\SectionService;
use App\Support\Actor;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$admin = get_userdata((int) $manifest['users']['admin']);
$sections = app(SectionService::class);
$section = $sections->create(Page::query()->findOrFail($manifest['pages']['a']), ['name' => 'Wyścig'], $admin);
$rounds = [];

for ($index = 0; $index < 12; $index++) {
	$existing = $index % 2 === 1;
	$field = $sections->createField($section, ['type' => 'text', 'label' => 'Wyścig ' . $index], $admin);

	if ($existing) {
		app(ContentService::class)->save($field, 'Start', 0, Actor::user($admin));
	}

	$rounds[] = ['field' => $field->id, 'base' => $existing ? 1 : 0, 'kind' => $existing ? 'existing' : 'new'];
}

// Start za chwilę: obu procesom wystarczy to na załadowanie WordPressa.
file_put_contents('/tmp/fmi-stage08-race.json', json_encode(['admin' => $admin->ID, 'start' => microtime(true) + 12, 'rounds' => $rounds]));
fwrite(STDOUT, "Wyścig przygotowany: 12 rund.\n");
