<?php

/**
 * Etap 03 — usunięcie danych testowych utworzonych przez setup.php.
 *
 * Usuwamy WYŁĄCZNIE obiekty z manifestu (po identyfikatorach), nigdy po wzorcu nazwy
 * i nigdy danych użytkownika.
 */

use App\Models\Project;

$manifestPath = '/tmp/fmi-stage03-fixtures.json';

if (! file_exists($manifestPath)) {
	fwrite(STDOUT, "Brak manifestu — nie ma czego sprzątać.\n");

	return;
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);

if (! is_array($manifest)) {
	fwrite(STDERR, "UWAGA: manifest nieczytelny, sprzątanie pominięte.\n");
	exit(1);
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$removedProjects = 0;

foreach ($manifest['projects'] ?? [] as $projectId) {
	$project = Project::withTrashed()->whereKey((int) $projectId)->first();

	if ($project) {
		// forceDelete: kaskady kluczy obcych usuwają podstrony, sekcje, pola i wartości.
		$project->forceDelete();
		$removedProjects++;
	}
}

$removedUsers = 0;

foreach ($manifest['users'] ?? [] as $role => $userId) {
	if ($role === 'admin') {
		continue; // konto właściciela — nigdy nie dotykamy
	}

	if (wp_delete_user((int) $userId)) {
		$removedUsers++;
	}
}

// Dziennik aktywności przeżywa usunięcie projektu (project_id → NULL), więc wpisy testowe
// usuwamy po PODMIOCIE: kontach testowych z manifestu. Konta właściciela nie dotykamy.
$testUserIds = array_values(array_map('intval', array_diff(
	(array) ($manifest['users'] ?? []),
	[(int) ($manifest['users']['admin'] ?? 0)],
)));

$removedActivity = $testUserIds === [] ? 0 : \App\Models\Activity::query()
	->where('subject_type', \App\Services\ActivityLogger::SUBJECT_USER)
	->whereIn('subject_id', $testUserIds)
	->delete();

// Wpisy projektowe tworzy konto właściciela, więc nie da się ich odfiltrować po wykonawcy.
// Bierzemy więc wpisy powstałe PO znaczniku z setup.php, których projekt już nie istnieje
// (został usunięty wyżej) i które w `properties` noszą sufiks tego przebiegu testów.
$watermark = (int) ($manifest['activity_watermark'] ?? 0);
$suffix = (string) ($manifest['suffix'] ?? '');

$orphanActivity = $suffix === '' ? 0 : \App\Models\Activity::query()
	->where('id', '>', $watermark)
	->whereNull('project_id')
	->where(function ($query) use ($suffix, $testUserIds) {
		$query->where('properties', 'like', '%' . $suffix . '%');

		if ($testUserIds !== []) {
			$query->orWhereIn('user_id', $testUserIds);
		}
	})
	->delete();

unlink($manifestPath);

fwrite(STDOUT, sprintf(
	"Sprzątnięto: projekty=%d, konta=%d, wpisy dziennika=%d (+%d osieroconych).\n",
	$removedProjects, $removedUsers, $removedActivity, $orphanActivity,
));
