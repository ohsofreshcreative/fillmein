<?php

/**
 * Etap 08 — jeden z dwóch procesów wyścigu. Każda runda startuje w ustalonej chwili, wspólnej dla obu.
 * Wynik trafia do /tmp/fmi-stage08-race-{a|b}.json.
 */

use App\Exceptions\ContentConflictException;
use App\Models\Field;
use App\Services\ContentService;
use App\Support\Actor;

$who = $args[0] ?? 'x';
$plan = json_decode((string) file_get_contents('/tmp/fmi-stage08-race.json'), true);
$actor = Actor::user(get_userdata((int) $plan['admin']));
$content = app(ContentService::class);
$results = [];

foreach ($plan['rounds'] as $index => $round) {
	$field = Field::query()->findOrFail($round['field']);
	$value = "Proces {$who}";
	$at = $plan['start'] + $index * 0.4;

	while (microtime(true) < $at) {
		usleep(50);
	}

	try {
		$saved = $content->save($field, $value, $round['base'], $actor);
		$results[$index] = ['result' => 'saved', 'version' => $saved->version, 'value' => $value, 'late' => microtime(true) - $at];
	} catch (ContentConflictException $conflict) {
		$results[$index] = ['result' => 'conflict', 'version' => $conflict->current->version, 'value' => $value, 'attempted' => $conflict->attempted, 'current' => $conflict->current->value, 'late' => microtime(true) - $at];
	} catch (\Throwable $error) {
		$results[$index] = ['result' => 'error', 'error' => get_class($error) . ': ' . $error->getMessage()];
	}
}

file_put_contents("/tmp/fmi-stage08-race-{$who}.json", json_encode($results));
