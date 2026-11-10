<?php

/**
 * Porównanie migawek projektów: przed migracją, po migracji, po wycofaniu, po ponownej migracji.
 * Argumenty: before.json after.json rollback.json again.json checks-before.json checks-after.json checks-rollback.json
 */

[, $before, $after, $rollback, $again, $cBefore, $cAfter, $cRollback] = array_map(
	fn ($file) => $file === null ? null : json_decode((string) file_get_contents($file), true),
	$argv + array_fill(0, 8, null),
);
$pass = 0;
$fail = 0;
$check = function (bool $ok, string $label) use (&$pass, &$fail) {
	$ok ? $pass++ : $fail++;
	echo ($ok ? 'PASS' : 'FAIL') . " {$label}\n";
};
$strip = fn (array $projects) => array_map(fn ($row) => array_diff_key($row, ['content_enabled' => 1, 'review_enabled' => 1]), $projects);

$check($before['marker'] && $after['marker'] && $rollback['marker'] && $again['marker'], 'WP-CLI pracował na izolowanej kopii (znacznik izolacji)');
$check(! $before['has_modules'] && ! $before['has_revisions'], 'kopia przed migracją: bez modułów i bez tabeli rewizji');
$check($after['has_modules'] && $after['has_revisions'], 'po migracji: kolumny modułów i tabela fmi_review_revisions');
$check(count($before['projects']) === count($after['projects']), 'liczba projektów bez zmian (' . count($before['projects']) . ')');
$check($strip($after['projects']) === $before['projects'], 'te same id, uuid, właściciele, członkowie, podstrony, wartości (hash treści) i linki klientów');

$modulesOk = true;
foreach ($after['projects'] as $row) {
	$modulesOk = $modulesOk && (int) $row['content_enabled'] === 1 && (int) $row['review_enabled'] === 0;
}
$check($modulesOk, 'istniejące projekty: Treści włączone, Design Review wyłączone');

$check(in_array('fmi_manage_reviews', $after['caps']['fmi_administrator'], true) && in_array('fmi_manage_reviews', $after['caps']['fmi_project_manager'], true), 'fmi_manage_reviews dla Administratora i Project Managera');
$check(! in_array('fmi_manage_reviews', $after['caps']['fmi_employee'], true), 'Pracownik bez fmi_manage_reviews');
foreach ($before['caps'] as $role => $caps) {
	$check(array_values(array_diff($caps, $after['caps'][$role])) === [], "{$role}: żadna dotychczasowa capability nie zniknęła");
}

$check(! $rollback['has_modules'] && ! $rollback['has_revisions'], 'wycofanie: kolumny i tabela usunięte');
$check($rollback['projects'] === $before['projects'], 'wycofanie: projekty identyczne jak przed migracją');
$check($again['has_modules'] && $strip($again['projects']) === $before['projects'], 'ponowna migracja po wycofaniu: działa, dane zgodne');

// Integralność całej bazy: wszystkie tabele poza fmi_projects (nowe kolumny zmieniają jej sumę) mają te same sumy po migracji.
$prefix = $cBefore['prefix'];
foreach ($cBefore['checksums'] as $table => $sum) {
	if ($table !== $prefix . 'fmi_projects') {
		$check(($cAfter['checksums'][$table] ?? null) === $sum, "po migracji CHECKSUM {$table} bez zmian");
	}
}
foreach ($cBefore['tables'] as $table => $count) {
	if ($table === $prefix . 'migrations') {
		$check(($cAfter['tables'][$table] ?? null) === $count + 1, "po migracji {$table}: dokładnie jeden nowy wpis ({$count} → " . ($count + 1) . ')');
	} elseif ($table !== $prefix . 'options' && $table !== $prefix . 'usermeta') {
		$check(($cAfter['tables'][$table] ?? null) === $count, "po migracji liczebność {$table} = {$count}");
	}
}
$check(($cAfter['tables'][$prefix . 'fmi_review_revisions'] ?? null) === 0, 'nowa tabela rewizji pusta');
$check(array_sum($cAfter['orphans']) === 0, 'po migracji 0 osieroconych wierszy (' . count($cAfter['orphans']) . ' relacji)');
$check(in_array($prefix . 'fmi_review_revisions.project_id -> ' . $prefix . 'fmi_projects.id', $cAfter['foreign_keys'], true), 'klucz obcy rewizji → projekt');
foreach ($cBefore['checksums'] as $table => $sum) {
	$check(($cRollback['checksums'][$table] ?? null) === $sum, "po wycofaniu CHECKSUM {$table} jak przed migracją");
}

echo "\nWYNIK: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
