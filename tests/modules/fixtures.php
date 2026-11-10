<?php

/**
 * Moduły projektu — dane do testu HTTP, dopisywane do manifestu etapu 03 (sprzątanie: tests/stage03/teardown.php).
 *
 * setup:    projekt R (tylko Design Review) i D (oba moduły) z podstroną i polem z wartością, członek = member,
 *           opiekun = manager; link klienta dla R i A (token tylko w pliku 0600).
 * register: dopisuje do manifestu projekty utworzone przez HTTP w tym przebiegu (nazwa z sufiksem).
 * state:    stan do asercji (moduły, liczniki, wpisy dziennika) → plik JSON.
 */

use App\Models\FieldValue;
use App\Models\Project;
use App\Policies\ProjectPolicy;
use App\Services\ClientAccessService;
use App\Services\PageService;
use App\Services\ProjectAssignmentService;
use App\Services\ProjectService;

$manifestPath = '/tmp/fmi-stage03-fixtures.json';
$extraPath = '/tmp/fmi-modules-extra.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$admin = get_user_by('id', (int) $manifest['users']['admin']);
wp_set_current_user($admin->ID);
$suffix = $manifest['suffix'];
$mode = $args[0] ?? '';

if ($mode === 'setup') {
	$projects = app(ProjectService::class);
	$created = [];

	foreach (['r' => ['review'], 'd' => ['content', 'review']] as $key => $modules) {
		$project = $projects->create([
			'name' => 'TEST moduły ' . strtoupper($key) . ' ' . $suffix,
			'manager_id' => (int) $manifest['users']['manager'],
			'modules' => $modules,
		], $admin);
		app(ProjectAssignmentService::class)->addMember($project, (int) $manifest['users']['member'], $admin);
		$page = app(PageService::class)->create($project, ['name' => 'Strona ' . strtoupper($key)], $admin);
		$manifest['projects'][$key] = $project->id;
		$manifest['pages'][$key] = $page->id;
		$created[$key] = $project;
	}

	$extra = ['tokens' => []];
	foreach (['a' => Project::query()->findOrFail($manifest['projects']['a']), 'r' => $created['r']] as $key => $project) {
		$extra['tokens'][$key] = parse_url(app(ClientAccessService::class)->generate($project, $admin, null, 'Test modułów')->url(), PHP_URL_PATH);
	}

	file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	file_put_contents($extraPath, json_encode($extra));
	chmod($extraPath, 0600);
	fwrite(STDOUT, "Moduły: projekty R i D gotowe.\n");

	return;
}

if ($mode === 'register') {
	foreach (Project::withTrashed()->where('name', 'like', '%' . $suffix . '%')->get() as $project) {
		if (! in_array($project->id, array_map('intval', $manifest['projects']), true)) {
			$manifest['projects']['http_' . $project->id] = $project->id;
		}
	}

	file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

	return;
}

// state
$users = $manifest['users'];
$policy = new ProjectPolicy();
$state = ['projects' => [], 'policy' => []];

foreach (Project::withTrashed()->where('name', 'like', '%' . $suffix . '%')->get() as $project) {
	$state['projects'][$project->name] = [
		'id' => $project->id,
		'content' => (bool) $project->content_enabled,
		'review' => (bool) $project->review_enabled,
		'values' => FieldValue::query()->where('project_id', $project->id)->count(),
		'pages' => $project->pages()->count(),
	];
}

foreach (['r', 'd', 'a'] as $key) {
	$project = Project::query()->find($manifest['projects'][$key]);

	foreach (['manager', 'member', 'outsider'] as $role) {
		$user = get_user_by('id', (int) $users[$role]);
		$state['policy'][$key][$role] = [
			'view' => $policy->before($user, 'view') ?? $policy->viewReview($user, $project)->allowed(),
			'manage' => $policy->before($user, 'manage') ?? $policy->manageReviews($user, $project)->allowed(),
			'manage_status' => $policy->before($user, 'manage') === null ? $policy->manageReviews($user, $project)->status() : null,
		];
	}
}

$state['activity_modules'] = \App\Models\Activity::query()
	->where('event', 'project.updated')
	->where('project_id', $manifest['projects']['a'])
	->where('properties', 'like', '%review_enabled%')
	->count();
$state['users_total'] = (int) count_users()['total_users'];
$state['projects_total'] = Project::withTrashed()->count();

file_put_contents($args[1], json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
