<?php

/**
 * Etap 08 — asercje po wyścigu dwóch procesów z tą samą wersją bazową.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\FieldRevision;
use App\Models\FieldValue;

$plan = json_decode((string) file_get_contents('/tmp/fmi-stage08-race.json'), true);
$a = json_decode((string) @file_get_contents('/tmp/fmi-stage08-race-a.json'), true);
$b = json_decode((string) @file_get_contents('/tmp/fmi-stage08-race-b.json'), true);

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];

$t('Oba procesy zakończyły wszystkie rundy, bez błędów', function () use ($a, $b, $plan, $same) {
	$same(count($plan['rounds']), count((array) $a), 'Proces a');
	$same(count($plan['rounds']), count((array) $b), 'Proces b');

	foreach ([...array_values((array) $a), ...array_values((array) $b)] as $result) {
		$same(true, $result['result'] !== 'error', 'Bez błędu: ' . ($result['error'] ?? 'ok'));
	}
});

$wins = ['a' => 0, 'b' => 0];

foreach ($plan['rounds'] as $index => $round) {
	$t("Runda {$index} ({$round['kind']}): jeden zapis, jeden konflikt, bez utraty i duplikatu", function () use ($a, $b, $index, $round, $same, $ok, &$wins) {
		$results = [$a[$index]['result'], $b[$index]['result']];
		sort($results);
		$same(['conflict', 'saved'], $results, 'Wyniki');

		$winner = $a[$index]['result'] === 'saved' ? $a[$index] : $b[$index];
		$loser = $a[$index]['result'] === 'saved' ? $b[$index] : $a[$index];
		$wins[$a[$index]['result'] === 'saved' ? 'a' : 'b']++;

		$values = FieldValue::query()->where('field_id', $round['field'])->get();
		$same(1, $values->count(), 'Jeden rekord FieldValue');
		$same($winner['value'], $values[0]->value, 'W bazie wartość zwycięzcy');
		$same($round['base'] + 1, $values[0]->version, 'Wersja podniesiona raz');
		$same($loser['value'], $loser['attempted'], 'Wartość przegranego wróciła do niego');
		$same($winner['value'], $loser['current'], 'Przegrany dostał wartość zwycięzcy');
		$same($round['base'] + 1, FieldRevision::query()->where('field_value_id', $values[0]->id)->count(), 'Rewizje: po jednej na udany zapis');
	});
}

$overlap = 0;

foreach ($plan['rounds'] as $index => $round) {
	$overlap += abs(($a[$index]['late'] ?? 1) - ($b[$index]['late'] ?? 0)) < 0.05 ? 1 : 0;
}

fwrite(STDOUT, sprintf("  INFO  zwycięstwa a=%d b=%d, rundy z końcem obu procesów w odstępie < 50 ms: %d/%d\n", $wins['a'], $wins['b'], $overlap, count($plan['rounds'])));

exit(Harness::summary('ETAP 08 — współbieżność'));
