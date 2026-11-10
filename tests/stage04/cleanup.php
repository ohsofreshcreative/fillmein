<?php

/**
 * Etap 04 — usunięcie plików podglądów zasianych przez seed.php (przed teardown etapu 03,
 * który usuwa projekty; wiersze cache znikają kaskadowo razem z projektem).
 */

use Illuminate\Support\Facades\Storage;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$removed = 0;

foreach ((array) ($manifest['figma_dirs'] ?? []) as $dir) {
	// Tylko katalogi projektów testowych pod figma/ — nigdy cały dysk.
	if (is_string($dir) && preg_match('~^figma/[0-9a-f-]{36}$~', $dir) === 1) {
		Storage::disk('fmi')->deleteDirectory($dir);
		$removed++;
	}
}

fwrite(STDOUT, "Usunięto katalogi podglądów testowych: {$removed}.\n");
