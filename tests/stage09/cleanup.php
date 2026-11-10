<?php

/**
 * Etap 09 — sprzątanie plików: wyłącznie ścieżki z manifestu testu i pliki zasobów projektów testowych.
 * Uruchamiane przed teardown, bo potrzebuje wierszy fmi_assets.
 */

use App\Models\Asset;
use Illuminate\Support\Facades\Storage;

$path = '/tmp/fmi-stage09-files.json';
$files = json_decode((string) @file_get_contents($path), true) ?: [];
$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$disk = Storage::disk('fmi');

foreach (Asset::withTrashed()->whereIn('project_id', array_values($manifest['projects'] ?? []))->get() as $asset) {
	foreach ([$asset->path, ...array_values($asset->variants ?? [])] as $relative) {
		$files[] = $disk->path($relative);
	}
}

$fixtures = realpath(__DIR__ . '/fixtures');
$storage = realpath($disk->path(''));
$removed = 0;
$directories = [];

foreach (array_unique($files) as $file) {
	$real = realpath($file);

	if ($real === false || ! (str_starts_with($real, $fixtures . '/') || str_starts_with($real, $storage . '/projects/'))) {
		continue;
	}

	$removed += unlink($real) ? 1 : 0;

	if (str_starts_with($real, $storage . '/projects/')) {
		$directories[dirname($real)] = true;
		$directories[dirname($real, 2)] = true;
	}
}

// rmdir usuwa tylko puste katalogi: najpierw zasoby, potem projekty.
$ordered = array_keys($directories);
usort($ordered, fn ($a, $b) => strlen($b) <=> strlen($a));

foreach ($ordered as $directory) {
	@rmdir($directory);
}

@unlink($path);
fwrite(STDOUT, "Usunięto pliki testowe: {$removed}\n");
