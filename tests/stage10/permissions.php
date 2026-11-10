<?php

/**
 * Etap 10 — uprawnienia synchronizacji, przeglądu treści i dziennika.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Project;
use Illuminate\Support\Facades\Gate;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$projectA = Project::query()->findOrFail($manifest['projects']['a']);
$users = array_map('get_userdata', $manifest['users']);

Harness::test('Pracownik bez przypisania → 404 na wszystkich uprawnieniach etapu', function () use ($users, $projectA) {
	foreach (['configureFields', 'editContent', 'viewActivity'] as $ability) {
		Harness::same(404, Gate::forUser($users['outsider'])->inspect($ability, $projectA)->status(), $ability);
	}
});

Harness::test('Administrator i opiekun mają synchronizację i dziennik', function () use ($users, $projectA) {
	foreach (['admin', 'manager'] as $role) {
		Harness::assert(Gate::forUser($users[$role])->allows('configureFields', $projectA), "{$role}: synchronizacja");
		Harness::assert(Gate::forUser($users[$role])->allows('viewActivity', $projectA), "{$role}: dziennik");
	}
});

exit(Harness::summary('ETAP 10 — uprawnienia'));
