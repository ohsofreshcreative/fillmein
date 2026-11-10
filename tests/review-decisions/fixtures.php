<?php

/**
 * Decyzje o rewizji — dane w manifeście etapu 03 (sprzątanie: tests/stage03/teardown.php, kaskady).
 * D: Design Review, opiekun = manager, członek = member (bez fmi_manage_reviews); rewizja 1 z linkiem prototypu
 *    niedostępnego pliku (API Figmy odmawia → wersja nieustalona).
 * Linki D: full (komentarz + akceptacja), noapprove, revoked, code (kod sekret-777, akceptacja).
 * E: obcy projekt (outsider nieprzypisany) z rewizją.
 * Tokeny: /tmp/fmi-review-decisions.json (0600, usuwany po teście).
 */

use App\Enums\ReviewRevisionStatus;
use App\Models\ReviewRevision;
use App\Services\ClientAccessService;
use App\Services\ProjectAssignmentService;
use App\Services\ProjectService;

$manifestPath = '/tmp/fmi-stage03-fixtures.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$admin = get_user_by('id', (int) $manifest['users']['admin']);
$manager = get_user_by('id', (int) $manifest['users']['manager']);
$member = get_user_by('id', (int) $manifest['users']['member']);
wp_set_current_user($admin->ID);

$projects = app(ProjectService::class);
$links = app(ClientAccessService::class);
$revision = fn ($project) => ReviewRevision::create([
	'project_id' => $project->id, 'number' => 1, 'title' => 'Rewizja 1', 'status' => ReviewRevisionStatus::Draft,
	'figma_url' => 'https://www.figma.com/proto/ZZtestNoAccess0000000000/Brak-dostepu?node-id=1-2',
	'figma_file_key' => 'ZZtestNoAccess0000000000', 'figma_node_id' => '1:2', 'created_by' => $admin->ID,
]);

$d = $projects->create(['name' => 'TEST decyzje D ' . $manifest['suffix'], 'client_name' => 'Klient', 'manager_id' => $manager->ID, 'modules' => ['review']], $admin);
app(ProjectAssignmentService::class)->addMember($d, $member->ID, $admin);
$revision($d);
$e = $projects->create(['name' => 'TEST decyzje E ' . $manifest['suffix'], 'manager_id' => $admin->ID, 'modules' => ['review']], $admin);
$revision($e);
$manifest['projects']['decisions_d'] = $d->id;
$manifest['projects']['decisions_e'] = $e->id;

$in = now()->addDays(30);
$tokens = [
	'full' => $links->generateReview($d, $manager, $in, 'Pełny', true, true),
	'noapprove' => $links->generateReview($d, $manager, $in, 'Bez akceptacji', true, false),
	'revoked' => $links->generateReview($d, $manager, $in, 'Odwołany', true, true),
	'code' => $links->generateReview($d, $manager, $in, 'Z kodem', true, true, 'sekret-777'),
	'foreign' => $links->generateReview($e, $admin, $in, 'Obcy', true, true),
];

file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$path = '/tmp/fmi-review-decisions.json';
touch($path);
chmod($path, 0600);
file_put_contents($path, json_encode([
	'd' => $d->id, 'e' => $e->id,
	'tokens' => array_map(fn ($issued) => $issued->token, $tokens),
	'access' => array_map(fn ($issued) => $issued->access->id, $tokens),
]));
echo "Decyzje: dane gotowe.\n";
