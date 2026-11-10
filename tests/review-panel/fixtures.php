<?php

/**
 * Panel Design Review — dane testowe dopisywane do manifestu etapu 03 (sprzątanie: tests/stage03/teardown.php,
 * kaskady usuwają rewizje i wątki).
 *
 * setup:  R (tylko Design Review, członek = member) — 3 wątki otwarte, 2 rozwiązane, rewizja 1 zaakceptowana;
 *         D (oba moduły, członek = member) — bez wątków i rewizji; 45 projektów „duży zbiór” (opiekun = manager,
 *         w tym jeden z nazwą 190 znaków i jeden z HTML w nazwie).
 * thread: <open|resolve> <klucz projektu> — zmiana statusu jednego wątku.
 * state:  liczniki z bazy → JSON (porównanie z HTML).
 */

use App\Enums\ActorType;
use App\Enums\ReviewRevisionStatus;
use App\Enums\ReviewThreadStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ReviewRevision;
use App\Models\ReviewThread;
use App\Services\ProjectAssignmentService;
use App\Services\ProjectService;

$manifestPath = '/tmp/fmi-stage03-fixtures.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$admin = get_user_by('id', (int) $manifest['users']['admin']);
wp_set_current_user($admin->ID);
$suffix = $manifest['suffix'];
$mode = $args[0] ?? '';
$save = function () use ($manifestPath, &$manifest) {
	file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};

if ($mode === 'setup') {
	$projects = app(ProjectService::class);
	$make = function (string $key, string $name, array $modules, bool $member) use ($projects, $admin, $manifest) {
		$project = $projects->create(['name' => $name, 'client_name' => 'Klient ' . $key, 'manager_id' => (int) $manifest['users']['manager'], 'modules' => $modules], $admin);
		if ($member) {
			app(ProjectAssignmentService::class)->addMember($project, (int) $manifest['users']['member'], $admin);
		}

		return $project;
	};

	$r = $make('R', 'TEST recenzja R ' . $suffix, ['review'], true);
	$d = $make('D', 'TEST recenzja D ' . $suffix, ['content', 'review'], true);
	$manifest['projects']['r'] = $r->id;
	$manifest['projects']['d'] = $d->id;

	$revision = ReviewRevision::create(['project_id' => $r->id, 'number' => 1, 'title' => 'Rewizja 1', 'status' => ReviewRevisionStatus::Approved, 'created_by' => $admin->ID, 'figma_url' => 'https://www.figma.com/proto/abcdefghij12/R']);
	foreach ([ReviewThreadStatus::Open, ReviewThreadStatus::Open, ReviewThreadStatus::Open, ReviewThreadStatus::Resolved, ReviewThreadStatus::Resolved] as $status) {
		ReviewThread::create(['project_id' => $r->id, 'revision_id' => $revision->id, 'status' => $status, 'created_by_type' => ActorType::User, 'created_by_user_id' => $admin->ID]);
	}
	// Wątek innego projektu (A, bez Design Review) nie może wpłynąć na liczniki R i D.
	ReviewThread::create(['project_id' => $manifest['projects']['a'], 'status' => ReviewThreadStatus::Open, 'created_by_type' => ActorType::User, 'created_by_user_id' => $admin->ID]);

	for ($i = 1; $i <= 45; $i++) {
		$name = match ($i) {
			1 => mb_substr('TEST długa nazwa ' . $suffix . ' ' . str_repeat('Bardzo długa nazwa projektu recenzji ', 10), 0, 190),
			2 => 'TEST <script>alert(1)</script> ' . $suffix,
			default => 'TEST duży zbiór ' . $i . ' ' . $suffix,
		};
		$manifest['projects']['big_' . $i] = $make('B' . $i, $name, ['review'], false)->id;
	}

	$save();
	fwrite(STDOUT, "Panel: dane gotowe (R, D, 45 projektów).\n");

	return;
}

if ($mode === 'thread') {
	$project = (int) $manifest['projects'][$args[2]];
	$thread = ReviewThread::query()->where('project_id', $project)->where('status', $args[1] === 'resolve' ? 'open' : 'resolved')->orderBy('id')->firstOrFail();
	$thread->update($args[1] === 'resolve'
		? ['status' => ReviewThreadStatus::Resolved, 'resolved_at' => now(), 'resolved_by' => $admin->ID]
		: ['status' => ReviewThreadStatus::Open, 'resolved_at' => null, 'resolved_by' => null]);

	return;
}

if ($mode === 'register') {
	return;
}

// state
$state = [];
foreach (['r', 'd', 'a'] as $key) {
	$id = (int) $manifest['projects'][$key];
	$latest = ReviewRevision::query()->where('project_id', $id)->orderByDesc('number')->first();
	$last = Activity::query()->where('project_id', $id)->max('created_at');
	$state[$key] = [
		'id' => $id,
		'open' => ReviewThread::query()->where('project_id', $id)->where('status', 'open')->count(),
		'revisions' => ReviewRevision::query()->where('project_id', $id)->count(),
		'latest_number' => $latest?->number,
		'latest_status' => $latest?->status->label(),
		'latest_url' => $latest?->figma_url,
		'last_activity' => max(array_filter([
			$last ? \Illuminate\Support\Carbon::parse($last) : null,
			Project::query()->find($id)?->updated_at,
		]))?->format('d.m.Y H:i'),
	];
}

$state['visible'] = [];
foreach (['admin', 'manager', 'member', 'outsider'] as $role) {
	$state['visible'][$role] = Project::query()->visibleTo(get_user_by('id', (int) $manifest['users'][$role]))
		->withModule(\App\Enums\ProjectModule::Review)->where('status', '!=', 'archived')->count();
}

file_put_contents($args[1], json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
