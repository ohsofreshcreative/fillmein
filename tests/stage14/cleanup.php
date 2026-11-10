<?php

/**
 * Etap 14 — pliki Figmy i dziennik projektów z manifestu. Uruchamiane przed teardown, bo dziennik wskazuje projekt
 * tylko do chwili jego usunięcia, a wpisy klienta nie mają konta testowego, po którym teardown mógłby je znaleźć.
 */

use App\Models\Activity;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$ids = array_map('intval', array_values($manifest['projects'] ?? []));
$disk = Storage::disk('fmi');
$directories = 0;

foreach (Project::withTrashed()->whereIn('id', $ids)->get() as $project) {
	$root = 'figma/' . $project->uuid;

	if ($project->uuid !== '' && $disk->exists($root)) {
		$directories += $disk->deleteDirectory($root) ? 1 : 0;
	}
}

$activity = $ids === [] ? 0 : Activity::query()->whereIn('project_id', $ids)->delete();

fwrite(STDOUT, sprintf("Sprzątnięto: katalogi Figmy=%d, wpisy dziennika=%d.\n", $directories, $activity));
