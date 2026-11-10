<?php

/**
 * Etap 03 — weryfikacja SKUTKÓW żądań POST z tests/stage03/http.sh.
 *
 * Przekierowanie 302 zwraca zarówno udany zapis, jak i odrzucenie walidacją (`back()`),
 * więc sam kod odpowiedzi nie dowodzi niczego. Tu sprawdzamy stan bazy po tych żądaniach.
 */

require __DIR__ . '/_harness.php';

use App\Models\Page;
use App\Models\Project;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);

if (! is_array($manifest)) {
	fwrite(STDERR, "BLOCKED: brak manifestu danych testowych.\n");
	exit(2);
}

$projectId = (int) $manifest['projects']['a'];
$sourcePageId = (int) $manifest['pages']['a'];

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];

$t('Skutek: podstrona dodana przez formularz istnieje i ma zapisany frame', function () use ($projectId, $ok, $same) {
	$page = Page::query()->where('project_id', $projectId)->where('name', 'Podstrona z testu HTTP')->first();

	$ok($page !== null, 'Podstrona dodana przez POST istnieje w bazie');
	$same('podstrona-z-testu-http', $page->slug, 'Slug wygenerowany z nazwy');
	$same('7:7', $page->figma_node_id, 'node-id wyliczony z linku do frame\'u');
});

$t('Skutek: POST z linkiem do PLIKU Figmy NIE utworzył podstrony (walidacja zadziałała)', function () use ($projectId, $same) {
	$same(0, (int) Page::query()->where('project_id', $projectId)->where('name', 'Zly frame')->count(),
		'Podstrona z nieprawidłowym linkiem nie została zapisana');
});

$t('Skutek: duplikat podstrony powstał przez HTTP i wskazuje źródło', function () use ($projectId, $sourcePageId, $ok, $same) {
	$copy = Page::query()->where('project_id', $projectId)->where('duplicated_from_id', $sourcePageId)->first();

	$ok($copy !== null, 'Duplikat istnieje');
	$same('Strona główna A (kopia)', $copy->name, 'Domyślna nazwa duplikatu');
	$ok((int) $copy->id !== $sourcePageId && $copy->uuid !== Page::query()->whereKey($sourcePageId)->value('uuid'),
		'Duplikat ma własną tożsamość');
});

$t('Skutek: żądania HTTP nie naruszyły izolacji projektów', function () use ($manifest, $same) {
	$same(1, (int) Page::query()->where('project_id', (int) $manifest['projects']['b'])->count(),
		'Projekt B nadal ma dokładnie jedną podstronę');
});

exit(Harness::summary('Etap 03 — skutki POST'));
