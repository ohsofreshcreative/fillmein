<?php

/**
 * Odłożenie prawdziwego połączenia Figmy na czas testów. Kopia to zaszyfrowana wartość opcji (plik 0600).
 * Zapis nie nadpisuje istniejącej kopii, żeby przerwany przebieg nie stracił połączenia.
 */

use App\Integrations\Figma\FigmaConnection;

$path = '/tmp/fmi-figma-hold.json';
$mode = $args[0] ?? '';

if ($mode === 'save') {
	if (! file_exists($path)) {
		touch($path);
		chmod($path, 0600);
		file_put_contents($path, json_encode(['value' => get_option(FigmaConnection::OPTION, null)]));
	}

	delete_option(FigmaConnection::OPTION);

	return;
}

if ($mode === 'restore' && file_exists($path)) {
	$value = json_decode((string) file_get_contents($path), true)['value'] ?? null;
	delete_option(FigmaConnection::OPTION);

	if (is_string($value) && $value !== '') {
		add_option(FigmaConnection::OPTION, $value, '', false);
	}

	unlink($path);
}
