<?php

/**
 * Krok 0 — skan plików, które `git add -A` mógłby dodać, pod kątem wzorców sekretów.
 *
 * Wypisuje WYŁĄCZNIE ścieżkę, nazwę wzorca i liczbę dopasowań — nigdy treści.
 * Nie czyta plików ignorowanych (m.in. `.ai-workflow/secrets/`) ani `key`/`key.pub`.
 *
 * Użycie: php tests/step00/scan-secrets.php  (kod wyjścia 1 = znaleziono podejrzane pliki)
 */

$theme = dirname(__DIR__, 2);
chdir($theme);

$patterns = [
	'figma_pat' => '/figd_[A-Za-z0-9_-]{30,}/',
	'private_key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
	'github_token' => '/gh[pousr]_[A-Za-z0-9]{30,}/',
	'anthropic_key' => '/sk-ant-[A-Za-z0-9_-]{20,}/',
	'aws_key' => '/AKIA[0-9A-Z]{16}/',
	'client_url_token' => '~/p/[A-Za-z0-9_-]{40,}~',
];

// Lista plików, które dodałoby `git add -A`: zmienione/nowe, nieignorowane.
exec('git status --porcelain --untracked-files=all -z', $out);
$entries = array_filter(explode("\0", implode("\n", $out)));
$files = [];

foreach ($entries as $entry) {
	$status = substr($entry, 0, 2);
	$path = substr($entry, 3);

	if ($path === '' || str_contains($status, 'D') || in_array($path, ['key', 'key.pub'], true)) {
		continue;
	}

	$files[] = $path;
}

// Jawnie fikcyjne tokeny atrap testowych (np. FakeFigma::TOKEN w etapie 04) — liczone osobno, nie jako sekret.
$fixtureMarker = 'TEST_TOKEN_DO_NOT_LEAK';
$hits = 0;
$fixtures = 0;

foreach ($files as $path) {
	if (! is_file($path) || filesize($path) > 5 * 1024 * 1024) {
		continue;
	}

	$content = (string) file_get_contents($path);

	foreach ($patterns as $name => $regex) {
		preg_match_all($regex, $content, $matches);
		$real = array_filter($matches[0], fn ($match) => ! str_contains($match, $fixtureMarker));
		$count = count($real);

		if (count($matches[0]) > $count) {
			$fixtures += count($matches[0]) - $count;
			fwrite(STDOUT, sprintf("  ATRAPA      %s  [%s × %d, znacznik fikcyjnego tokenu]\n", $path, $name, count($matches[0]) - $count));
		}

		if ($count > 0) {
			$hits++;
			fwrite(STDOUT, sprintf("  PODEJRZANE  %s  [%s × %d]\n", $path, $name, $count));
		}
	}
}

fwrite(STDOUT, sprintf("Przeskanowano %d plików do dodania, podejrzanych dopasowań: %d, atrap testowych: %d\n", count($files), $hits, $fixtures));
exit($hits > 0 ? 1 : 0);
