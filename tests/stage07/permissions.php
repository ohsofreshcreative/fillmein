<?php

/**
 * Etap 07 — uprawnienia panelu linków (ProjectPolicy@manageClientAccess) na kontach z tests/stage03/setup.php.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Project;
use Illuminate\Support\Facades\Gate;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);

if (! is_array($manifest)) {
	fwrite(STDERR, "BLOCKED: brak manifestu danych testowych.\n");
	exit(2);
}

$projectA = Project::query()->findOrFail($manifest['projects']['a']);
$users = array_map('get_userdata', $manifest['users']);

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];

$t('Administrator i opiekun zarządzają linkami klienta', function () use ($users, $projectA, $ok) {
	foreach (['admin', 'manager'] as $role) {
		$ok(Gate::forUser($users[$role])->allows('manageClientAccess', $projectA), "{$role}: dozwolone");
	}
});

$t('Pracownik bez przypisania → 404', function () use ($users, $projectA, $same) {
	$same(404, Gate::forUser($users['outsider'])->inspect('manageClientAccess', $projectA)->status(), 'Status');
});

$t('Przypisany pracownik bez capability → 403', function () use ($users, $projectA, $ok, $same) {
	$member = clone $users['member'];
	$member->allcaps['fmi_manage_client_access'] = false;
	$response = Gate::forUser($member)->inspect('manageClientAccess', $projectA);

	$ok($response->denied(), 'Odmowa');
	$same(null, $response->status(), 'Domyślny status odmowy (403), nie 404');
});

exit(Harness::summary('ETAP 07 — uprawnienia'));
