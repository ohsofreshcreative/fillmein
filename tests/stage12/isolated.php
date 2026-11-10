<?php

/**
 * Etap 12 — po imporcie: wpisy bez znacznika, użytkownicy, opcje i tabele fmi_* identyczne ze stanem sprzed importu.
 */

$before = json_decode((string) file_get_contents('/tmp/fmi-stage12-before.json'), true);
$applied = json_decode((string) file_get_contents('/tmp/fmi-stage12-applied.json'), true);
$failed = 0;

foreach ($before as $key => $value) {
	$guarded = str_contains($key, 'fmi_') || in_array($key, ['wp_users', 'wp_options', 'posts_without_source', 'options_hash', 'cron_hooks', 'users_hash', 'untagged_posts_hash'], true);

	if ($guarded && $applied[$key] !== $value) {
		fwrite(STDOUT, sprintf("  FAIL  %s: %s → %s\n", $key, $value, $applied[$key]));
		$failed++;
	}
}

fwrite(STDOUT, sprintf(
	"  %s  Po imporcie bez zmian poza wpisami ze znacznikiem (wp_posts %d → %d, wp_postmeta %d → %d)\n",
	$failed ? 'FAIL' : 'PASS',
	$before['wp_posts'],
	$applied['wp_posts'],
	$before['wp_postmeta'],
	$applied['wp_postmeta'],
));

exit($failed ? 1 : 0);
