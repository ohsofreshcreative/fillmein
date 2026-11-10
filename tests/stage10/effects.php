<?php

/**
 * Etap 10 — skutki żądań HTTP w bazie i dzienniku.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Activity;
use App\Models\Field;
use App\Models\FieldRevision;
use App\Models\FieldValue;
use App\Models\Section;
use App\Support\ActivityPresenter;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$ids = json_decode((string) @file_get_contents('/tmp/fmi-stage10-ids.json'), true);
$project = (int) $manifest['projects']['a'];
$page = (int) $manifest['pages']['a'];
$field = fn (string $node) => Field::query()->whereIn('section_id', Section::query()->where('page_id', $page)->select('id'))->where('figma_node_id', $node)->first();

Harness::test('Stan po synchronizacji przez HTTP', function () use ($field, $page) {
	Harness::same('Nagłówek główny', $field('10:301')->label, 'Etykieta');
	Harness::assert($field('10:306') !== null, 'Nowa warstwa');
	Harness::assert($field('10:302')->archived_at !== null, 'Usunięta w archiwum');
	Harness::same(['10:200', '10:300', '10:600', '10:400', '10:500'], Section::query()->where('page_id', $page)->where('source', 'figma')->orderBy('position')->pluck('figma_node_id')->all(), 'Kolejność sekcji');
});

Harness::test('Treść usuniętego pola została', function () use ($field, $ids) {
	$value = FieldValue::query()->findOrFail($ids['value']);

	Harness::same('Treść od klienta', $value->value, 'Wartość');
	Harness::same($field('10:302')->id, $value->field_id, 'To samo pole');
	Harness::same(1, FieldRevision::query()->where('field_value_id', $value->id)->count(), 'Rewizja');
});

Harness::test('Pole z ryzykowną zmianą nie zmieniło typu bez wyboru', function () use ($ids) {
	$risk = Field::query()->whereIn('section_id', Section::query()->where('page_id', $ids['pages']['risk'])->select('id'))->where('figma_node_id', '10:201')->first();

	Harness::same('text', $risk->type->value, 'Typ');
});

Harness::test('Oznaczenie do poprawy zapisane poza wersją treści', function () use ($project) {
	$value = FieldValue::query()->where('project_id', $project)->whereNotNull('review_requested_at')->sole();

	Harness::same('Do sprawdzenia', $value->review_note, 'Komentarz');
	Harness::same(1, $value->version, 'Wersja bez zmian');
	Harness::same(1, FieldRevision::query()->where('field_value_id', $value->id)->count(), 'Bez nowej rewizji');
});

Harness::test('Dziennik: synchronizacja z podsumowaniem, oznaczenia, bez sekretów', function () use ($manifest, $project, $ids) {
	$events = Activity::query()->where('id', '>', (int) $manifest['activity_watermark'])->where('project_id', $project)->get();
	$sync = $events->where('event', 'figma.import_applied')->where('subject_id', (int) $manifest['pages']['a'])->sortByDesc('id')->first();

	$counts = $sync->properties['counts'];

	Harness::same([1, 3, 1, 1], [$counts['create'], $counts['update'], $counts['moved'], $counts['archive']], 'Liczniki');
	Harness::assert(str_contains((string) ActivityPresenter::context($sync), 'przeniesione 1'), 'Opis');

	foreach (['content.review_requested', 'content.review_cleared', 'content.updated'] as $event) {
		Harness::assert($events->contains('event', $event), "Wpis {$event}");
	}

	Harness::assert(! str_contains($events->toJson(), $ids['token']), 'Bez tokenu');
});

exit(Harness::summary('ETAP 10 — skutki'));
