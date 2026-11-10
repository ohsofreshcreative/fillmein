<?php

/**
 * Etap 09 — uprawnienia do plików projektu (ProjectPolicy@view i @editContent).
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Project;
use Illuminate\Support\Facades\Gate;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$projectA = Project::query()->findOrFail($manifest['projects']['a']);
$users = array_map('get_userdata', $manifest['users']);

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];

$t('Pracownicy projektu widzą pliki i je dodają', function () use ($users, $projectA, $ok) {
	foreach (['admin', 'manager', 'member'] as $role) {
		$ok(Gate::forUser($users[$role])->allows('view', $projectA), "{$role}: odczyt");
		$ok(Gate::forUser($users[$role])->allows('editContent', $projectA), "{$role}: edycja");
	}
});

$t('Pracownik bez przypisania → 404 na odczycie i edycji', function () use ($users, $projectA, $same) {
	$same(404, Gate::forUser($users['outsider'])->inspect('view', $projectA)->status(), 'Odczyt');
	$same(404, Gate::forUser($users['outsider'])->inspect('editContent', $projectA)->status(), 'Edycja');
});

exit(Harness::summary('ETAP 09 — uprawnienia'));
