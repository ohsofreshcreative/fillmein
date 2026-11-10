<?php

/**
 * Etap 08 — uprawnienia edycji treści (ProjectPolicy@editContent).
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

$t('Administrator, opiekun i przypisany pracownik edytują treść', function () use ($users, $projectA, $ok) {
	foreach (['admin', 'manager', 'member'] as $role) {
		$ok(Gate::forUser($users[$role])->allows('editContent', $projectA), "{$role}: dozwolone");
	}
});

$t('Pracownik bez przypisania → 404', function () use ($users, $projectA, $same) {
	$same(404, Gate::forUser($users['outsider'])->inspect('editContent', $projectA)->status(), 'Status');
});

$t('Przypisany pracownik bez capability → 403', function () use ($users, $projectA, $ok, $same) {
	$member = clone $users['member'];
	$member->allcaps['fmi_edit_content'] = false;
	$response = Gate::forUser($member)->inspect('editContent', $projectA);

	$ok($response->denied(), 'Odmowa');
	$same(null, $response->status(), 'Domyślny status odmowy (403), nie 404');
});

exit(Harness::summary('ETAP 08 — uprawnienia'));
