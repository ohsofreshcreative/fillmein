<?php

/**
 * Dyskusje i statusy — dane w manifeście etapu 03 (sprzątanie: tests/stage03/teardown.php, kaskady). Bez API Figmy.
 * D: Design Review, opiekun = manager, członek = member; rewizja; wątki: t1 (manager), t2 (member), t3 (manager).
 * E: obcy projekt (opiekun = admin) z wątkiem i wiadomością.
 * Wynik: /tmp/fmi-review-discussions.json.
 */

use App\Enums\ReviewRevisionStatus;
use App\Models\ReviewRevision;
use App\Services\ProjectAssignmentService;
use App\Services\ProjectService;
use App\Services\ReviewCommentService;

$manifestPath = '/tmp/fmi-stage03-fixtures.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$admin = get_user_by('id', (int) $manifest['users']['admin']);
$manager = get_user_by('id', (int) $manifest['users']['manager']);
$member = get_user_by('id', (int) $manifest['users']['member']);
wp_set_current_user($admin->ID);

$projects = app(ProjectService::class);
$comments = app(ReviewCommentService::class);
$revision = function ($project) use ($admin) {
	return ReviewRevision::create(['project_id' => $project->id, 'number' => 1, 'title' => 'Rewizja 1', 'status' => ReviewRevisionStatus::Draft, 'figma_file_key' => 'abcdefghij12', 'created_by' => $admin->ID]);
};

$d = $projects->create(['name' => 'TEST dyskusje D ' . $manifest['suffix'], 'manager_id' => $manager->ID, 'modules' => ['review']], $admin);
app(ProjectAssignmentService::class)->addMember($d, $member->ID, $admin);
$manifest['projects']['discussions_d'] = $d->id;
$revision($d);
$t1 = $comments->create($d, ['body' => 'Uwaga opiekuna', 'context' => 'Nagłówek'], $manager);
$t2 = $comments->create($d, ['body' => 'Uwaga członka', 'context' => 'Stopka'], $member);
$t3 = $comments->create($d, ['body' => 'Druga uwaga opiekuna', 'context' => 'Koszyk'], $manager);

$e = $projects->create(['name' => 'TEST dyskusje E ' . $manifest['suffix'], 'manager_id' => $admin->ID, 'modules' => ['review']], $admin);
$manifest['projects']['discussions_e'] = $e->id;
$revision($e);
$te = $comments->create($e, ['body' => 'Obca uwaga', 'context' => 'X'], $admin);

file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents('/tmp/fmi-review-discussions.json', json_encode([
	'd' => $d->id, 'e' => $e->id,
	't1' => $t1->id, 't2' => $t2->id, 't3' => $t3->id, 'te' => $te->id,
	'c1' => $t1->comments()->first()->id, 'c2' => $t2->comments()->first()->id, 'ce' => $te->comments()->first()->id,
]));
echo "Dyskusje: dane gotowe.\n";
