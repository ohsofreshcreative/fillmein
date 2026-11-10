<?php

/**
 * Etap 05 — skutki żądań HTTP w bazie (po tests/stage05/http.sh).
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Activity;
use App\Models\Field;
use App\Models\FigmaNodeRule;
use App\Models\Section;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$pageA = (int) $manifest['pages']['a'];
$pageB = (int) $manifest['pages']['b'];

$t = [Harness::class, 'test'];
$same = [Harness::class, 'same'];

$t('Jedno zatwierdzenie = 5 sekcji i 24 pola (odrzucone żądania nic nie zapisały)', function () use ($pageA, $same) {
	$sections = Section::query()->where('page_id', $pageA);
	$same(5, (clone $sections)->count(), 'Sekcje podstrony A');
	$same(24, Field::query()->whereIn('section_id', $sections->select('id'))->count(), 'Pola podstrony A');
});

$t('Reguła przywrócenia stopki zapisana tylko dla podstrony A', function () use ($pageA, $pageB, $same) {
	$same(['10:900' => 'include'], FigmaNodeRule::query()->where('page_id', $pageA)->pluck('action', 'node_id')->all(), 'Reguły A');
	$same(0, FigmaNodeRule::query()->where('page_id', $pageB)->count() + Section::query()->where('page_id', $pageB)->count(), 'Podstrona B bez zmian');
});

$t('Dziennik: jeden import i jedna zmiana reguły z tego przebiegu', function () use ($manifest, $same) {
	$events = Activity::query()->where('id', '>', (int) $manifest['activity_watermark'])->where('project_id', $manifest['projects']['a']);
	$same(1, (clone $events)->where('event', 'figma.import_applied')->count(), 'Import');
	$same(1, (clone $events)->where('event', 'figma.node_rule_changed')->count(), 'Reguła');
});

exit(Harness::summary('ETAP 05 — skutki HTTP'));
