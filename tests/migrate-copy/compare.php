<?php

/**
 * Porównanie próby migracji na kopii (tests/migrate-copy/run.sh). Ogólne — bez wiedzy o konkretnej migracji.
 * Argumenty: before after rollback again (migawki projektów) oraz checks-before after rollback again (sumy bazy).
 * Zmienne: PENDING — liczba migracji; ALTERED — tabele (bez prefiksu), które migracja celowo zmienia.
 */

[, $before, $after, $rollback, $again, $cBefore, $cAfter, $cRollback, $cAgain] = array_map(
	fn ($file) => $file === null ? null : json_decode((string) file_get_contents($file), true),
	$argv + array_fill(0, 9, null),
);
$pending = (int) getenv('PENDING');
$prefix = $cBefore['prefix'];
$altered = array_map(fn ($t) => $prefix . $t, array_filter(explode(' ', (string) getenv('ALTERED'))));
$pass = 0;
$fail = 0;
$check = function (bool $ok, string $label) use (&$pass, &$fail) {
	$ok ? $pass++ : $fail++;
	echo ($ok ? 'PASS' : 'FAIL') . " {$label}\n";
};

$check($before['marker'] && $after['marker'] && $rollback['marker'] && $again['marker'], 'WP-CLI pracował na izolowanej kopii (znacznik izolacji)');
$check(count($after['projects']) === count($before['projects']), 'liczba projektów bez zmian (' . count($before['projects']) . ')');
$check($after['projects'] === $before['projects'] || $altered !== [], 'projekty: te same id, uuid, właściciele, członkowie, moduły, podstrony, hash treści, linki');
foreach ($before['caps'] as $role => $caps) {
	$check(array_values(array_diff($caps, $after['caps'][$role])) === [], "{$role}: żadna dotychczasowa capability nie zniknęła");
}

$new = array_values(array_diff(array_keys($cAfter['tables']), array_keys($cBefore['tables'])));
$check($new !== [] || $altered !== [], 'migracja dodała tabele: ' . ($new ? implode(', ', $new) : '—'));
$check(array_diff(array_keys($cBefore['tables']), array_keys($cAfter['tables'])) === [], 'żadna tabela nie zniknęła');

foreach ($cBefore['checksums'] as $table => $sum) {
	if (! in_array($table, $altered, true)) {
		$check(($cAfter['checksums'][$table] ?? null) === $sum, "po migracji CHECKSUM {$table} bez zmian");
	}
}
foreach ($cBefore['tables'] as $table => $count) {
	if ($table === $prefix . 'migrations') {
		$check(($cAfter['tables'][$table] ?? null) === $count + $pending, "{$table}: +{$pending}");
	} elseif (! in_array($table, [$prefix . 'options', $prefix . 'usermeta'], true)) {
		$check(($cAfter['tables'][$table] ?? null) === $count, "po migracji liczebność {$table} = {$count}");
	}
}
foreach ($new as $table) {
	$check(($cAfter['tables'][$table] ?? null) === 0, "nowa tabela {$table} pusta");
}
$check(array_sum($cAfter['orphans']) === 0, 'po migracji 0 osieroconych wierszy (' . count($cAfter['orphans']) . ' relacji)');
$check(array_diff($cBefore['foreign_keys'], $cAfter['foreign_keys']) === [], 'dotychczasowe klucze obce zachowane (' . count($cBefore['foreign_keys']) . ')');
foreach (array_diff($cAfter['foreign_keys'], $cBefore['foreign_keys']) as $key) {
	echo "INFO nowy klucz obcy: {$key}\n";
}

$check(array_keys($cRollback['tables']) === array_keys($cBefore['tables']), 'wycofanie: zestaw tabel jak przed migracją');
foreach ($cBefore['checksums'] as $table => $sum) {
	$check(($cRollback['checksums'][$table] ?? null) === $sum, "po wycofaniu CHECKSUM {$table} jak przed migracją");
}
$check($rollback['projects'] === $before['projects'], 'wycofanie: projekty identyczne jak przed migracją');
$check(array_keys($cAgain['tables']) === array_keys($cAfter['tables']) && $again['projects'] === $after['projects'], 'ponowna migracja po wycofaniu: ten sam schemat i dane');

echo "\nWYNIK: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
