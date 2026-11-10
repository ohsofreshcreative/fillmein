<?php

/**
 * Wspólne sprawdzenia bazy dla testu odtworzenia: liczebność tabel, CHECKSUM TABLE tabel fmi,
 * klucze obce i liczba osieroconych wierszy dla każdego klucza obcego tabel fmi.
 * Ten sam zestaw zapytań uruchamiamy na bazie strony i na bazie odtworzonej, więc wynik jest porównywalny 1:1.
 *
 * Bez wartości kolumn: wynik zawiera wyłącznie nazwy tabel i liczby.
 *
 * @param callable(string): array<int, array<string, mixed>> $rows
 */
function fmi_restore_checks(callable $rows, string $prefix): array
{
	$q = fn (string $name) => '`' . str_replace('`', '', $name) . '`';
	$result = ['tables' => [], 'checksums' => [], 'foreign_keys' => [], 'orphans' => []];

	$tables = array_map(
		fn ($row) => (string) array_values($row)[0],
		$rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE" ORDER BY TABLE_NAME'),
	);

	foreach ($tables as $table) {
		if (! str_starts_with($table, $prefix)) {
			continue;
		}

		$result['tables'][$table] = (int) array_values($rows('SELECT COUNT(*) FROM ' . $q($table))[0])[0];

		if (str_starts_with($table, $prefix . 'fmi_')) {
			$result['checksums'][$table] = (string) ($rows('CHECKSUM TABLE ' . $q($table))[0]['Checksum'] ?? '');
		}
	}

	$keys = $rows(
		'SELECT k.TABLE_NAME t, k.COLUMN_NAME c, k.REFERENCED_TABLE_NAME rt, k.REFERENCED_COLUMN_NAME rc, k.CONSTRAINT_NAME n
		FROM information_schema.KEY_COLUMN_USAGE k
		WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
		ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION'
	);

	foreach ($keys as $key) {
		$name = $key['t'] . '.' . $key['c'] . ' -> ' . $key['rt'] . '.' . $key['rc'];
		$result['foreign_keys'][] = $name;
		$result['orphans'][$name] = (int) array_values($rows(
			'SELECT COUNT(*) FROM ' . $q($key['t']) . ' a LEFT JOIN ' . $q($key['rt']) . ' b ON b.' . $q($key['rc']) . ' = a.' . $q($key['c'])
			. ' WHERE a.' . $q($key['c']) . ' IS NOT NULL AND b.' . $q($key['rc']) . ' IS NULL'
		)[0])[0];
	}

	// Odwołania do użytkowników WP nie mają kluczy obcych (inna tabela, inny silnik zarządzania), więc liczymy je jawnie.
	foreach ($tables as $table) {
		if (! str_starts_with($table, $prefix . 'fmi_')) {
			continue;
		}

		$columns = array_map(fn ($row) => (string) array_values($row)[0], $rows(
			'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "' . addslashes($table) . '"'
			. ' AND COLUMN_NAME IN ("user_id", "created_by", "updated_by", "owner_id", "invited_by", "author_id", "actor_id")'
		));

		foreach ($columns as $column) {
			$name = $table . '.' . $column . ' -> ' . $prefix . 'users.ID';
			$result['orphans'][$name] = (int) array_values($rows(
				'SELECT COUNT(*) FROM ' . $q($table) . ' a LEFT JOIN ' . $q($prefix . 'users') . ' u ON u.ID = a.' . $q($column)
				. ' WHERE a.' . $q($column) . ' IS NOT NULL AND a.' . $q($column) . ' > 0 AND u.ID IS NULL'
			)[0])[0];
		}
	}

	ksort($result['orphans']);

	return $result;
}
