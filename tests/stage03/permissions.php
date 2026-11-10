<?php

/**
 * Etap 03 — testy uprawnień na REALNYCH kontach i projektach (dane z setup.php).
 * Sprawdzamy ProjectPolicy przez Gate, tak jak robią to kontrolery i FormRequesty.
 */

require __DIR__ . '/_harness.php';

use App\Models\Page;
use App\Models\Project;
use App\Services\RoleManager;
use Illuminate\Support\Facades\Gate;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);

if (! is_array($manifest)) {
	fwrite(STDERR, "BLOCKED: brak manifestu danych testowych (uruchom setup.php).\n");
	exit(2);
}

$projectA = Project::query()->whereKey($manifest['projects']['a'])->firstOrFail();
$projectB = Project::query()->whereKey($manifest['projects']['b'])->firstOrFail();

$manager = get_userdata($manifest['users']['manager']);
$member = get_userdata($manifest['users']['member']);
$outsider = get_userdata($manifest['users']['outsider']);
$admin = get_userdata($manifest['users']['admin']);

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];

$t('Capabilities ról są zgodne z RoleManager (autoryzacja nie zależy od nazwy roli)', function () use ($manager, $member, $ok) {
	foreach (RoleManager::CAPABILITIES[RoleManager::PROJECT_MANAGER] as $cap) {
		$ok($manager->has_cap($cap), "Project Manager ma {$cap}");
	}

	foreach (RoleManager::CAPABILITIES[RoleManager::EMPLOYEE] as $cap) {
		$ok($member->has_cap($cap), "Pracownik ma {$cap}");
	}

	$ok(! $member->has_cap('fmi_manage_all_projects'), 'Pracownik NIE ma dostępu do wszystkich projektów');
	$ok(! $member->has_cap('fmi_create_projects'), 'Pracownik NIE może tworzyć projektów');
	$ok(! $member->has_cap('fmi_manage_users'), 'Pracownik NIE zarządza użytkownikami');
});

$t('IDOR: pracownik bez przypisania dostaje 404 (denyAsNotFound), nie 403', function () use ($outsider, $projectA, $ok, $same) {
	$response = Gate::forUser($outsider)->inspect('view', $projectA);

	$ok($response->denied(), 'Odmowa dostępu do obcego projektu');
	$same(404, $response->status(), 'Status 404 — brak wycieku informacji o istnieniu projektu');
});

$t('Pracownik przypisany widzi projekt i może konfigurować jego podstrony', function () use ($member, $projectA, $ok) {
	$ok(Gate::forUser($member)->allows('view', $projectA), 'Przypisany pracownik widzi projekt');
	$ok(Gate::forUser($member)->allows('managePages', $projectA), 'Ma fmi_configure_fields → zarządza podstronami');
});

$t('Pracownik przypisany NIE może zmieniać ustawień, zespołu ani usuwać projektu', function () use ($member, $projectA, $ok) {
	$ok(Gate::forUser($member)->denies('update', $projectA), 'Brak prawa do ustawień projektu');
	$ok(Gate::forUser($member)->denies('manageMembers', $projectA), 'Brak prawa do zespołu');
	$ok(Gate::forUser($member)->denies('delete', $projectA), 'Brak prawa do usunięcia');
});

$t('Opiekun projektu może edytować ustawienia, zespół i usuwać swój projekt', function () use ($manager, $projectA, $ok) {
	$ok(Gate::forUser($manager)->allows('view', $projectA), 'Opiekun widzi projekt');
	$ok(Gate::forUser($manager)->allows('update', $projectA), 'Opiekun edytuje ustawienia');
	$ok(Gate::forUser($manager)->allows('manageMembers', $projectA), 'Opiekun zarządza zespołem');
	$ok(Gate::forUser($manager)->allows('managePages', $projectA), 'Opiekun zarządza podstronami');
	$ok(Gate::forUser($manager)->allows('delete', $projectA), 'Opiekun usuwa projekt');
});

$t('Administrator ma dostęp do wszystkich projektów (reguła before)', function () use ($admin, $projectA, $projectB, $ok) {
	$ok(Gate::forUser($admin)->allows('view', $projectA), 'Dostęp do projektu A');
	$ok(Gate::forUser($admin)->allows('view', $projectB), 'Dostęp do projektu B');
	$ok(Gate::forUser($admin)->allows('managePages', $projectB), 'Zarządzanie podstronami projektu B');
});

$t('visibleTo ogranicza listę projektów do przypisanych', function () use ($member, $outsider, $manager, $projectA, $projectB, $ok, $same) {
	$memberIds = Project::query()->visibleTo($member)->pluck('id')->map(fn ($i) => (int) $i)->all();
	$same([(int) $projectA->id], $memberIds, 'Pracownik widzi wyłącznie projekt, do którego jest przypisany');

	$same([], Project::query()->visibleTo($outsider)->pluck('id')->all(), 'Nieprzypisany pracownik nie widzi żadnego projektu');

	$managerIds = Project::query()->visibleTo($manager)->pluck('id')->map(fn ($i) => (int) $i)->all();
	$ok(in_array((int) $projectA->id, $managerIds, true) && in_array((int) $projectB->id, $managerIds, true),
		'Opiekun widzi oba swoje projekty');
});

$t('Podstrona jest pobierana w zakresie projektu — obce ID nie trafia do projektu', function () use ($projectA, $manifest, $same) {
	$foreignPageId = (int) $manifest['pages']['b'];

	$same(null, $projectA->pages()->whereKey($foreignPageId)->first(),
		'Podstrona projektu B nie jest osiągalna przez relację projektu A');
});

$t('Dezaktywowane konto traci wszystkie capabilities aplikacji', function () use ($member, $projectA, $ok) {
	/** @var \App\Services\UserService $users */
	$users = app(\App\Services\UserService::class);
	$admins = get_users(['role' => 'administrator', 'number' => 1]);

	$users->deactivate($member, $admins[0]);

	$refreshed = new \WP_User($member->ID);
	$ok(! $refreshed->has_cap('fmi_access'), 'Brak fmi_access po dezaktywacji');
	$ok(Gate::forUser($refreshed)->denies('view', $projectA), 'Dezaktywowane konto nie widzi projektu');

	$users->reactivate($refreshed, $admins[0]);
	$ok((new \WP_User($member->ID))->has_cap('fmi_access'), 'Reaktywacja przywraca dostęp');
});

exit(Harness::summary('Etap 03 — uprawnienia'));
