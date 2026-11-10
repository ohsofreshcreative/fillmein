<?php

/**
 * Odświeżenie ciasteczek sesji w manifeście danych testowych.
 *
 * Testy uprawnień dezaktywują i reaktywują konto, a dezaktywacja (zgodnie z założeniem
 * aplikacji) niszczy wszystkie sesje użytkownika. Ciasteczka wygenerowane w setup.php
 * są więc po nich nieważne — przed testami HTTP wydajemy nowe.
 */

$manifestPath = '/tmp/fmi-stage03-fixtures.json';
$manifest = json_decode((string) @file_get_contents($manifestPath), true);

if (! is_array($manifest)) {
	fwrite(STDERR, "BLOCKED: brak manifestu danych testowych.\n");
	exit(2);
}

foreach ($manifest['users'] as $role => $userId) {
	$manifest['cookies'][$role] = LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie((int) $userId, time() + 3600, 'logged_in');
}

file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

fwrite(STDOUT, "Ciasteczka sesji testowych odświeżone.\n");
