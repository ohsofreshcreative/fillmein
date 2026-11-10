<?php

/**
 * Porównanie wyników: strona vs kopia odtworzona. Ścisłe: tabele fmi i użytkownicy WP (liczebność),
 * CHECKSUM tabel fmi, lista kluczy obcych, zero osieroconych wierszy. Pozostałe tabele WP (opcje, transienty,
 * sesje) mogą się zmienić między eksportem a liczeniem, więc różnica jest tylko informacją.
 * Argumenty: live.json, restored.json. Kod 0 — zgodne, 1 — rozbieżności.
 */

[, $liveFile, $restoredFile] = $argv + [null, '', ''];
$live = json_decode((string) file_get_contents($liveFile), true);
$restored = json_decode((string) file_get_contents($restoredFile), true);
$prefix = $live['prefix'];
$strict = fn (string $table) => str_starts_with($table, $prefix . 'fmi_') || in_array($table, [$prefix . 'users', $prefix . 'usermeta'], true);
$fail = 0;
$pass = 0;

$check = function (bool $ok, string $label) use (&$fail, &$pass) {
	$ok ? $pass++ : $fail++;
	echo ($ok ? 'PASS' : 'FAIL') . " {$label}\n";
};

echo "| Tabela | Strona | Kopia |\n|---|---:|---:|\n";
foreach (array_unique([...array_keys($live['tables']), ...array_keys($restored['tables'])]) as $table) {
	$a = $live['tables'][$table] ?? null;
	$b = $restored['tables'][$table] ?? null;
	echo "| {$table} | " . ($a ?? '—') . ' | ' . ($b ?? '—') . " |\n";
}
echo "\n";

foreach ($live['tables'] as $table => $count) {
	if ($strict($table)) {
		$check(($restored['tables'][$table] ?? null) === $count, "liczebność {$table} = {$count}");
	} elseif (($restored['tables'][$table] ?? null) !== $count) {
		echo "INFO {$table}: strona {$count}, kopia " . ($restored['tables'][$table] ?? 'brak') . " (tabela zmienna, poza porównaniem ścisłym)\n";
	}
}

$check(array_keys($live['tables']) == array_keys($restored['tables']), 'ten sam zestaw tabel (' . count($live['tables']) . ')');

foreach ($live['checksums'] as $table => $sum) {
	$check(($restored['checksums'][$table] ?? null) === $sum, "CHECKSUM {$table}");
}

$check($live['foreign_keys'] === $restored['foreign_keys'], 'klucze obce odtworzone (' . count($live['foreign_keys']) . ')');

foreach ($restored['orphans'] as $relation => $count) {
	$check($count === 0 && ($live['orphans'][$relation] ?? null) === 0, "relacja bez sierot: {$relation}");
}

echo "\nWYNIK: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
