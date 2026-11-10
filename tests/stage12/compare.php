<?php

/**
 * Etap 12 — porównanie dwóch stanów z counts.php. Kod 1 przy jakiejkolwiek różnicy.
 */

[$first, $second] = $args;

$a = json_decode((string) file_get_contents("/tmp/fmi-stage12-{$first}.json"), true);
$b = json_decode((string) file_get_contents("/tmp/fmi-stage12-{$second}.json"), true);
$failed = 0;

foreach (array_keys($a + $b) as $key) {
	if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
		fwrite(STDOUT, sprintf("  FAIL  %s: %s → %s\n", $key, $a[$key] ?? 'brak', $b[$key] ?? 'brak'));
		$failed++;
	}
}

$fmi = array_filter(array_keys($a), fn ($key) => str_contains($key, 'fmi_'));

fwrite(STDOUT, sprintf(
	"  %s  %s = %s: %d kluczy, w tym %d tabel fmi_* (wp_posts=%d, wp_postmeta=%d, wp_users=%d, wp_options=%d, bez znacznika=%d)\n",
	$failed ? 'FAIL' : 'PASS',
	$first,
	$second,
	count($a),
	count($fmi),
	$b['wp_posts'],
	$b['wp_postmeta'],
	$b['wp_users'],
	$b['wp_options'],
	$b['posts_without_source'],
));

exit($failed ? 1 : 0);
