<?php

/**
 * Etap 03 — przygotowanie danych do testów HTTP i uprawnień.
 *
 * Tworzy TRWAŁE dane testowe (konta WP + projekty + podstrony), bo żądanie HTTP przychodzi
 * z osobnego procesu i nie zobaczyłoby danych z niezatwierdzonej transakcji.
 * Wszystko jest usuwane przez tests/stage03/teardown.php na podstawie zapisanego manifestu.
 */

use App\Models\Page;
use App\Models\Project;
use App\Services\PageService;
use App\Services\ProjectAssignmentService;
use App\Services\ProjectService;
use App\Services\RoleManager;

$manifestPath = '/tmp/fmi-stage03-fixtures.json';

$admins = get_users(['role' => 'administrator', 'number' => 1]);
$admin = $admins[0] ?? null;

if (! $admin) {
	fwrite(STDERR, "BLOCKED: brak administratora WordPressa.\n");
	exit(2);
}

wp_set_current_user($admin->ID);

/** @var ProjectService $projects */
$projects = app(ProjectService::class);
/** @var PageService $pages */
$pages = app(PageService::class);
/** @var ProjectAssignmentService $assignments */
$assignments = app(ProjectAssignmentService::class);

$suffix = wp_generate_password(6, false, false);

// Znacznik: wpisy dziennika powstałe PO tej wartości pochodzą z tego przebiegu testów.
$activityWatermark = (int) \App\Models\Activity::query()->max('id');

$makeUser = function (string $login, string $role) use ($suffix): int {
	$id = wp_insert_user([
		'user_login' => $login . '_' . $suffix,
		'user_email' => $login . '_' . $suffix . '@fmi-test.invalid',
		'user_pass' => wp_generate_password(24),
		'role' => $role,
		'first_name' => 'Test',
		'last_name' => ucfirst($login),
	]);

	if (is_wp_error($id)) {
		fwrite(STDERR, 'BLOCKED: nie udało się utworzyć konta testowego: ' . $id->get_error_message() . "\n");
		exit(2);
	}

	return (int) $id;
};

$managerId = $makeUser('fmitest_pm', RoleManager::PROJECT_MANAGER);
$memberId = $makeUser('fmitest_member', RoleManager::EMPLOYEE);
$outsiderId = $makeUser('fmitest_outsider', RoleManager::EMPLOYEE);

$projectA = $projects->create([
	'name' => 'TEST HTTP projekt A ' . $suffix,
	'client_name' => 'Klient A',
	'manager_id' => $managerId,
], $admin);

$assignments->addMember($projectA, $memberId, $admin);

$projectB = $projects->create([
	'name' => 'TEST HTTP projekt B ' . $suffix,
	'manager_id' => $managerId,
], $admin);

$pageA = $pages->create($projectA, [
	'name' => 'Strona główna A',
	'figma_frame_url' => 'https://www.figma.com/design/abcdefghij12/A?node-id=1-2',
], $admin);

$pageB = $pages->create($projectB, ['name' => 'Strona główna B'], $admin);

$cookie = function (int $userId): string {
	return LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie($userId, time() + 3600, 'logged_in');
};

$manifest = [
	'suffix' => $suffix,
	'activity_watermark' => $activityWatermark,
	'site' => home_url(),
	'users' => [
		'admin' => $admin->ID,
		'manager' => $managerId,
		'member' => $memberId,
		'outsider' => $outsiderId,
	],
	'projects' => ['a' => $projectA->id, 'b' => $projectB->id],
	'pages' => ['a' => $pageA->id, 'b' => $pageB->id],
	'cookies' => [
		'admin' => $cookie($admin->ID),
		'manager' => $cookie($managerId),
		'member' => $cookie($memberId),
		'outsider' => $cookie($outsiderId),
	],
];

file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

fwrite(STDOUT, "Dane testowe utworzone. Manifest: {$manifestPath}\n");
fwrite(STDOUT, sprintf(
	"  projekt A=%d (opiekun=%d, członek=%d), projekt B=%d, podstrona A=%d, podstrona B=%d, obcy pracownik=%d\n",
	$projectA->id, $managerId, $memberId, $projectB->id, $pageA->id, $pageB->id, $outsiderId,
));
