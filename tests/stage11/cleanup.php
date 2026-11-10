<?php

/**
 * Etap 11 — sprzątanie plików: wyłącznie zasoby i eksporty projektów testowych z manifestu.
 * Uruchamiane przed teardown, bo potrzebuje wierszy fmi_assets i fmi_projects.
 */

use App\Models\Asset;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$disk = Storage::disk('fmi');
$projects = array_values($manifest['projects'] ?? []);
$removed = 0;
$directories = [];

foreach (Asset::withTrashed()->whereIn('project_id', $projects)->get() as $asset) {
	foreach ([$asset->path, ...array_values($asset->variants ?? [])] as $relative) {
		if (str_starts_with($relative, 'projects/') && $disk->exists($relative)) {
			$removed += $disk->delete($relative) ? 1 : 0;
			$directories[dirname($disk->path($relative))] = true;
			$directories[dirname($disk->path($relative), 2)] = true;
		}
	}
}

foreach (Project::withTrashed()->whereIn('id', $projects)->get() as $project) {
	$root = 'exports/' . $project->uuid;

	foreach ($disk->allFiles($root) as $file) {
		$removed += $disk->delete($file) ? 1 : 0;
		$directories[dirname($disk->path($file))] = true;
	}

	$directories[$disk->path($root)] = true;
}

$directories[$disk->path('exports')] = true;

// rmdir usuwa tylko puste katalogi: najpierw najgłębsze.
$ordered = array_keys($directories);
usort($ordered, fn ($a, $b) => strlen($b) <=> strlen($a));

foreach ($ordered as $directory) {
	@rmdir($directory);
}

fwrite(STDOUT, "Usunięto pliki testowe: {$removed}\n");
