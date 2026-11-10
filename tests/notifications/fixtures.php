<?php

/**
 * Powiadomienia — dane w manifeście etapu 03 (sprzątanie: tests/stage03/teardown.php, kaskady usuwają powiadomienia).
 * N: Design Review, autor = admin, opiekun = manager, członek = member; outsider nieprzypisany.
 *    Zdarzenia przez serwisy: uwaga gościa (XSS w treści), uwaga opiekuna, odpowiedź członka, decyzja opiekuna.
 * O: drugi projekt (opiekun = admin) z uwagą — do sprawdzenia cudzych powiadomień.
 * Wynik: /tmp/fmi-notifications.json (0600).
 */

use App\Enums\ReviewRevisionStatus;
use App\Enums\ReviewRevisionStatus as S;
use App\Models\ReviewRevision;
use App\Services\ClientAccessService;
use App\Services\ProjectAssignmentService;
use App\Services\ProjectService;
use App\Services\ReviewCommentService;
use App\Services\ReviewDecisionService;
use App\Services\ReviewDiscussionService;
use App\Support\ReviewGuest;
use Illuminate\Support\Str;

$manifestPath = '/tmp/fmi-stage03-fixtures.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$admin = get_user_by('id', (int) $manifest['users']['admin']);
$manager = get_user_by('id', (int) $manifest['users']['manager']);
$member = get_user_by('id', (int) $manifest['users']['member']);
wp_set_current_user($admin->ID);

$projects = app(ProjectService::class);
$comments = app(ReviewCommentService::class);
$revision = fn ($project) => ReviewRevision::create([
	'project_id' => $project->id, 'number' => 1, 'title' => 'Rewizja 1', 'status' => ReviewRevisionStatus::Draft,
	'figma_url' => 'https://www.figma.com/proto/ZZtestNoAccess0000000000/Brak-dostepu?node-id=1-2',
	'figma_file_key' => 'ZZtestNoAccess0000000000', 'figma_node_id' => '1:2', 'created_by' => $admin->ID,
]);

$n = $projects->create(['name' => 'TEST powiadomienia N ' . $manifest['suffix'], 'manager_id' => $manager->ID, 'modules' => ['review']], $admin);
app(ProjectAssignmentService::class)->addMember($n, $member->ID, $admin);
$rev = $revision($n);
$o = $projects->create(['name' => 'TEST powiadomienia O ' . $manifest['suffix'], 'manager_id' => $admin->ID, 'modules' => ['review']], $admin);
$revision($o);
$manifest['projects']['notifications_n'] = $n->id;
$manifest['projects']['notifications_o'] = $o->id;

$link = app(ClientAccessService::class)->generateReview($n, $manager, now()->addDays(7), 'Gość', true, true);
$guest = new ReviewGuest($link->access, 'Ewa <b>Gość</b>');
$t1 = $comments->create($n, ['body' => '<script>alert(1)</script> Uwaga gościa', 'context' => 'Nagłówek'], $guest);
$t2 = $comments->create($n, ['body' => 'Uwaga opiekuna', 'context' => 'Stopka'], $manager);
app(ReviewDiscussionService::class)->reply($n, $t2, 'Odpowiedź członka', (string) Str::uuid(), $member);
$decision = app(ReviewDecisionService::class)->decide($n, $rev, S::ChangesRequested, 'Popraw stopkę', (string) Str::uuid(), $manager);
$to = $comments->create($o, ['body' => 'Obca uwaga', 'context' => 'X'], $admin);

file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$path = '/tmp/fmi-notifications.json';
touch($path);
chmod($path, 0600);
file_put_contents($path, json_encode([
	'n' => $n->id, 'o' => $o->id, 't1' => $t1->number, 't2' => $t2->number, 't1_id' => $t1->id, 't2_id' => $t2->id,
	'decision' => $decision->id, 'access' => $link->access->id, 'token' => $link->token,
]));
echo "Powiadomienia: dane gotowe.\n";
