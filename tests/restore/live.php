<?php

/**
 * Sprawdzenia na bazie strony (WP-CLI eval-file). Argument: ścieżka pliku wynikowego JSON.
 */

require __DIR__ . '/checks.php';

global $wpdb;

$out = $args[0] ?? '';

if ($out === '') {
	fwrite(STDERR, "Brak ścieżki wyniku.\n");
	exit(1);
}

$result = fmi_restore_checks(fn (string $sql) => $wpdb->get_results($sql, ARRAY_A) ?: [], $wpdb->prefix);
$result['prefix'] = $wpdb->prefix;

file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
