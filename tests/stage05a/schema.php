<?php

/**
 * Etap 05a — schemat po poszerzeniu ID warstw Figmy: 6 kolumn VARCHAR(255) z zachowanym nullable
 * oraz wszystkie indeksy i klucze unique na tych kolumnach (porównanie po składzie kolumn, nie po nazwie,
 * bo nazwy generowane przez Laravela zawierają prefiks tabel WordPressa).
 *
 * Na końcu wypisuje SHOW INDEX dla 6 tabel (do raportu: porównanie przed i po migracji).
 * Test tylko czyta — niczego nie zmienia.
 */

require __DIR__ . '/../stage03/_harness.php';

global $wpdb;

$prefix = $wpdb->prefix;
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];

/** tabela => [kolumna, nullable] */
$columns = [
	'fmi_pages' => ['figma_node_id', true],
	'fmi_sections' => ['figma_node_id', true],
	'fmi_fields' => ['figma_node_id', true],
	'fmi_figma_frames' => ['node_id', false],
	'fmi_figma_previews' => ['node_id', false],
	'fmi_figma_node_rules' => ['node_id', false],
];

/** Indeksy, które muszą istnieć: tabela => lista [kolumny, unique]. */
$indexes = [
	'fmi_pages' => [[['project_id', 'figma_node_id'], false]],
	'fmi_sections' => [[['page_id', 'figma_node_id'], true]],
	'fmi_fields' => [[['project_id', 'figma_node_id'], false]],
	'fmi_figma_frames' => [[['project_id', 'file_key', 'node_id'], true]],
	'fmi_figma_previews' => [[['frame_id', 'node_id'], true], [['project_id', 'file_key', 'node_id'], false]],
	'fmi_figma_node_rules' => [[['page_id', 'node_id'], true]],
];

/** Indeksy tabeli jako nazwa => ['unique' => bool, 'columns' => [...]]. */
$indexesOf = function (string $table) use ($wpdb, $prefix): array {
	$result = [];

	foreach ($wpdb->get_results("SHOW INDEX FROM `{$prefix}{$table}`", ARRAY_A) as $row) {
		$result[$row['Key_name']]['unique'] = (int) $row['Non_unique'] === 0;
		$result[$row['Key_name']]['columns'][(int) $row['Seq_in_index']] = $row['Column_name'];
	}

	return array_map(fn ($i) => ['unique' => $i['unique'], 'columns' => array_values($i['columns'])], $result);
};

foreach ($columns as $table => [$column, $nullable]) {
	Harness::test("{$table}.{$column}: VARCHAR(255), " . ($nullable ? 'NULL' : 'NOT NULL'), function () use ($wpdb, $prefix, $table, $column, $nullable, $same) {
		$row = $wpdb->get_row($wpdb->prepare(
			'SELECT CHARACTER_MAXIMUM_LENGTH AS len, DATA_TYPE AS type, IS_NULLABLE AS nullable
			 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			$prefix . $table,
			$column,
		), ARRAY_A);

		$same('varchar', $row['type'] ?? null, 'Typ kolumny');
		$same(255, (int) ($row['len'] ?? 0), 'CHARACTER_MAXIMUM_LENGTH');
		$same($nullable ? 'YES' : 'NO', $row['nullable'] ?? null, 'Nullable');
	});
}

foreach ($indexes as $table => $expected) {
	Harness::test("{$table}: indeksy na kolumnie ID Figmy zachowane", function () use ($indexesOf, $table, $expected, $ok) {
		$existing = $indexesOf($table);

		foreach ($expected as [$cols, $unique]) {
			$found = array_filter($existing, fn ($i) => $i['columns'] === $cols && $i['unique'] === $unique);
			$ok($found !== [], ($unique ? 'Brak unique ' : 'Brak indeksu ') . '(' . implode(', ', $cols) . ')');
		}
	});
}

Harness::test('ROW_FORMAT tabel DYNAMIC/COMPRESSED (klucze na VARCHAR(255) w utf8mb4)', function () use ($wpdb, $prefix, $columns, $ok) {
	foreach (array_keys($columns) as $table) {
		$format = strtolower((string) $wpdb->get_var($wpdb->prepare(
			'SELECT ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$prefix . $table,
		)));
		$ok(in_array($format, ['dynamic', 'compressed'], true), "{$table}: ROW_FORMAT={$format}");
	}
});

fwrite(STDOUT, "\n  SHOW INDEX (tabela: indeks [unique] (kolumny)):\n");

foreach (array_keys($columns) as $table) {
	foreach ($indexesOf($table) as $name => $index) {
		fwrite(STDOUT, sprintf("    %s: %s%s (%s)\n", $table, $name, $index['unique'] ? ' [unique]' : '', implode(', ', $index['columns'])));
	}
}

exit(Harness::summary('ETAP 05a — schemat'));
