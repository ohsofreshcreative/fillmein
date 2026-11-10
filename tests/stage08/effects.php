<?php

/**
 * Etap 08 — skutki żądań HTTP w bazie i ścieżki zapisu w kodzie. Tokeny nie są wypisywane.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Activity;
use App\Models\Field;
use App\Models\FieldRevision;
use App\Models\FieldValue;
use App\Models\RepeaterRow;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$ids = json_decode((string) @file_get_contents('/tmp/fmi-stage08-ids.json'), true);
$value = fn (string $name, string $row = '') => FieldValue::query()->where('field_id', $ids['fields'][$name])->where('row_key', $row)->first();

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];

$t('Konflikt HTTP: w bazie wartość pierwszego zapisu', function () use ($value, $same) {
	$same('Pierwszy zapis', $value('other')->value, 'Wartość');
	$same(1, $value('other')->version, 'Wersja');
	$same(1, FieldRevision::query()->where('field_value_id', $value('other')->id)->count(), 'Jedna rewizja');
});

$t('Przywrócenie HTTP: treść i rewizje', function () use ($value, $ids, $same, $ok) {
	$text = $value('text');
	$revisions = FieldRevision::query()->where('field_value_id', $text->id)->orderBy('id')->get();

	$same('Pierwsza wersja', $text->value, 'Wartość');
	$same(['Pierwsza wersja', 'Druga wersja', 'Pierwsza wersja'], $revisions->pluck('value')->all(), 'Historia');
	$same('client', $revisions[2]->actor_type->value, 'Autor');
	$same($ids['revisions']['first'], $revisions[0]->id, 'Stara rewizja na miejscu');
});

$t('Pracownik zapisał pole ukryte przed klientem', function () use ($value, $same) {
	$same('Od zespołu', $value('hidden')->value, 'Wartość');
	$same('user', $value('hidden')->updated_by_type->value, 'Autor');
});

$t('Odrzucone zapisy nic nie zmieniły', function () use ($ids, $same, $value) {
	$same(0, FieldValue::query()->where('field_id', $ids['fields']['archived'])->count(), 'Pole archiwalne');
	$same(0, FieldValue::query()->where('field_id', $ids['fields']['row_field'])->where('row_key', $ids['rows']['second'])->count(), 'Wiersz innego pola');
	$same('Treść B', $value('foreign')->value, 'Pole innego projektu');
	$same(2, RepeaterRow::query()->where('field_id', $ids['fields']['repeater'])->count(), 'Limit wierszy');
});

$t('Dziennik: autozapis jednego pola to jeden wpis; przywrócenie osobno, bez treści', function () use ($manifest, $ids, $same, $ok) {
	$events = Activity::query()->where('id', '>', (int) $manifest['activity_watermark'])->where('project_id', $manifest['projects']['a'])->get();

	$same(1, $events->where('event', 'content.updated')->where('subject_id', $ids['fields']['wysiwyg'])->where('actor_type', \App\Enums\ActorType::Client)->count(), 'Jeden wpis mimo wielu zapisów');
	$ok($events->contains('event', 'content.restored'), 'Wpis przywrócenia');
	$ok(! str_contains($events->toJson(), 'Autozapis 3'), 'Bez treści w dzienniku');
});

$t('Wszystkie ścieżki zapisu wartości idą przez ContentService', function () use ($same) {
	$direct = [];
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(get_theme_file_path('app')));

	foreach ($files as $file) {
		if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'Services/ContentService.php')) {
			continue;
		}

		$code = (string) file_get_contents($file->getPathname());

		if (preg_match('/FieldValue::(query\(\)\s*->[^;]*->(update|delete|insert)|create|update|updateOrCreate|insert)\b/s', $code)) {
			$direct[] = basename($file->getPathname());
		}
	}

	// Kopia wartości przy duplikowaniu podstrony startuje od wersji 0 i nie jest edycją treści.
	$same(['PageDuplicationService.php'], $direct, 'Pliki z bezpośrednim zapisem');
});

exit(Harness::summary('ETAP 08 — skutki'));
