<?php

/**
 * Pinezki i komentarze — dane testowe w manifeście etapu 03 (sprzątanie: tests/stage03/teardown.php, kaskady).
 * L: Design Review, opiekun = manager, członek = member; prototyp z pliku FMI_FIGMA_LIVE_URL; ekrany przez API Figmy:
 *    s1 = frame z linku, s2 = pierwszy podrzędny FRAME (jeśli jest), s3 = kopia s1 oznaczona jako niepotwierdzona.
 * F: obcy projekt (opiekun = admin) z rewizją i ekranem (wiersz w bazie, bez API).
 * Wynik: /tmp/fmi-review-comments.json (identyfikatory, bez linku Figmy).
 */

use App\Enums\ReviewRevisionStatus;
use App\Integrations\Figma\FigmaClient;
use App\Integrations\Figma\FigmaUrl;
use App\Models\ReviewRevision;
use App\Models\ReviewScreen;
use App\Services\ProjectAssignmentService;
use App\Services\ProjectService;
use App\Services\ReviewScreenService;
use App\Services\ReviewService;

$manifestPath = '/tmp/fmi-stage03-fixtures.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$admin = get_user_by('id', (int) $manifest['users']['admin']);
$manager = get_user_by('id', (int) $manifest['users']['manager']);
wp_set_current_user($admin->ID);

$url = (string) getenv('FMI_FIGMA_LIVE_URL');
$key = FigmaUrl::fileKey($url);
$node = FigmaUrl::nodeId($url);
if (! $key || ! $node) {
	fwrite(STDERR, "BLOCKED: brak FMI_FIGMA_LIVE_URL z node-id\n");
	exit(2);
}

$projects = app(ProjectService::class);
$l = $projects->create(['name' => 'TEST pinezki L ' . $manifest['suffix'], 'client_name' => 'Klient L', 'manager_id' => $manager->ID, 'modules' => ['review']], $admin);
app(ProjectAssignmentService::class)->addMember($l, (int) $manifest['users']['member'], $admin);
$manifest['projects']['comments_l'] = $l->id;
$encoded = str_replace(':', '-', $node);
$revision = app(ReviewService::class)->savePrototype($l, "https://www.figma.com/proto/{$key}/Test?node-id={$encoded}", $admin);

$screens = app(ReviewScreenService::class);
$s1 = $screens->add($l, $revision, "https://www.figma.com/design/{$key}/Test?node-id={$encoded}", $manager);

$s2 = null;
$data = app(FigmaClient::class)->fileNodes($key, [$node], 1);
foreach ($data['nodes'][$node]['document']['children'] ?? [] as $child) {
	if (($child['type'] ?? '') === 'FRAME' && FigmaUrl::isNodeId($child['id'] ?? null)) {
		$s2 = $screens->add($l, $revision, "https://www.figma.com/design/{$key}/Test?node-id=" . str_replace(':', '-', $child['id']), $manager);
		break;
	}
}

$s3 = ReviewScreen::create([
	'project_id' => $l->id, 'revision_id' => $revision->id, 'figma_frame_id' => $s1->figma_frame_id, 'file_key' => $key,
	'node_id' => '999999:1', 'name' => 'TEST ekran niepotwierdzony', 'width' => $s1->width, 'height' => $s1->height,
	'surface_confirmed' => false, 'position' => 99, 'created_by' => $admin->ID,
]);

$f = $projects->create(['name' => 'TEST pinezki F ' . $manifest['suffix'], 'manager_id' => $admin->ID, 'modules' => ['review']], $admin);
$manifest['projects']['comments_f'] = $f->id;
$fr = ReviewRevision::create(['project_id' => $f->id, 'number' => 1, 'title' => 'Rewizja 1', 'status' => ReviewRevisionStatus::Draft, 'figma_file_key' => $key, 'created_by' => $admin->ID]);
$fs = ReviewScreen::create(['project_id' => $f->id, 'revision_id' => $fr->id, 'file_key' => $key, 'node_id' => '1:1', 'name' => 'Obcy ekran', 'width' => 100, 'height' => 100, 'surface_confirmed' => true, 'created_by' => $admin->ID]);

file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents('/tmp/fmi-review-comments.json', json_encode([
	'l' => $l->id, 'f' => $f->id, 'a' => (int) $manifest['projects']['a'],
	's1' => $s1->id, 's1_confirmed' => $s1->surface_confirmed, 's1_size' => [$s1->width, $s1->height],
	's2' => $s2?->id, 's2_confirmed' => $s2?->surface_confirmed,
	's3' => $s3->id, 'foreign_screen' => $fs->id,
]));
echo 'Pinezki: dane gotowe; s1 ' . ($s1->surface_confirmed ? 'potwierdzony' : 'NIEPOTWIERDZONY') . " {$s1->width}×{$s1->height}"
	. ($s2 ? ', s2 ' . ($s2->surface_confirmed ? 'potwierdzony' : 'niepotwierdzony') . " {$s2->width}×{$s2->height}" : ', bez s2') . "\n";
