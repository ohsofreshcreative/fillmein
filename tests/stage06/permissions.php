<?php

/**
 * Etap 06 — uprawnienia konfiguratora (ProjectPolicy@configureFields) na kontach z tests/stage03/setup.php.
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
$projectB = Project::query()->findOrFail($manifest['projects']['b']);
$users = array_map('get_userdata', $manifest['users']);

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];

$t('Administrator, opiekun i przypisany pracownik konfigurują pola projektu A', function () use ($users, $projectA, $ok) {
	foreach (['admin', 'manager', 'member'] as $role) {
		$ok(Gate::forUser($users[$role])->allows('configureFields', $projectA), "{$role}: dozwolone");
	}
});

$t('Pracownik bez przypisania: 404 w obu projektach; członek A: 404 w projekcie B', function () use ($users, $projectA, $projectB, $same) {
	$same(404, Gate::forUser($users['outsider'])->inspect('configureFields', $projectA)->status(), 'Obcy, A');
	$same(404, Gate::forUser($users['outsider'])->inspect('configureFields', $projectB)->status(), 'Obcy, B');
	$same(404, Gate::forUser($users['member'])->inspect('configureFields', $projectB)->status(), 'Członek A, B');
});

$t('Przypisany pracownik bez capability fmi_configure_fields: odmowa 403', function () use ($users, $projectA, $ok, $same) {
	// Kopia w pamięci — bez zapisu ról w bazie.
	$member = clone $users['member'];
	$member->allcaps['fmi_configure_fields'] = false;
	$response = Gate::forUser($member)->inspect('configureFields', $projectA);

	$ok($response->denied(), 'Odmowa');
	$same(null, $response->status(), 'Domyślny status odmowy (403), nie 404');
});

exit(Harness::summary('ETAP 06 — uprawnienia'));
