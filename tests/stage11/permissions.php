<?php

/**
 * Etap 11 — uprawnienia eksportu i mapowania.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Project;
use Illuminate\Support\Facades\Gate;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$project = Project::query()->findOrFail($manifest['projects']['a']);
$users = array_map('get_userdata', $manifest['users']);
$status = fn (string $role) => Gate::forUser($users[$role])->inspect('export', $project)->status();

Harness::test('Pracownik bez przypisania → 404', function () use ($status) {
	Harness::same(404, $status('outsider'), 'obcy');
});

// Odmowa bez kodu w Response::deny() to 403 na poziomie HTTP (sprawdza http.sh).
Harness::test('Przypisany pracownik bez capability → odmowa', function () use ($users, $project) {
	Harness::assert(! $users['member']->has_cap('fmi_export'), 'brak capability');
	Harness::assert(Gate::forUser($users['member'])->denies('export', $project), 'członek');
});

Harness::test('Administrator i opiekun mają eksport', function () use ($users, $project) {
	foreach (['admin', 'manager'] as $role) {
		Harness::assert(Gate::forUser($users[$role])->allows('export', $project), $role);
	}
});

exit(Harness::summary('ETAP 11 — uprawnienia'));
