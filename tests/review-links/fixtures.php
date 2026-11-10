<?php

/**
 * Linki recenzji — dane w manifeście etapu 03 (sprzątanie: tests/stage03/teardown.php, kaskady).
 * R: Design Review + Treści, opiekun = manager; rewizja z prototypem z niedostępnego pliku Figmy (odmowa Figmy),
 *    ekran z obrazem testowym (bez API), wątek t1 (opiekun).
 * F: obcy projekt z wątkiem, ekranem i własnym linkiem recenzji.
 * Linki R: full (komentarz + akceptacja), readonly, noapprove, code (kod), code2, expired, revoked, live (do
 * unieważnienia w trakcie sesji), expiring (do wygaszenia w trakcie), content (link Treści).
 * Tokeny: /tmp/fmi-review-links.json (0600, usuwany po teście) — tylko dla testu.
 */

use App\Enums\ReviewRevisionStatus;
use App\Models\FigmaFrame;
use App\Models\FigmaPreview;
use App\Models\ReviewRevision;
use App\Models\ReviewScreen;
use App\Services\ClientAccessService;
use App\Services\ProjectService;
use App\Services\ReviewCommentService;
use Illuminate\Support\Facades\Storage;

$manifestPath = '/tmp/fmi-stage03-fixtures.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$admin = get_user_by('id', (int) $manifest['users']['admin']);
$manager = get_user_by('id', (int) $manifest['users']['manager']);
wp_set_current_user($admin->ID);

$projects = app(ProjectService::class);
$links = app(ClientAccessService::class);
$comments = app(ReviewCommentService::class);
// Obraz ekranu generowany lokalnie (bez API Figmy i bez projektu klienta): 600×1200.
$image = imagecreatetruecolor(600, 1200);
imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 240));
ob_start();
imagepng($image);
$png = (string) ob_get_clean();
[$pngWidth, $pngHeight] = [600, 1200];

$setup = function (string $name, $managerUser) use ($projects, $admin, $png, $pngWidth, $pngHeight, $comments) {
	$project = $projects->create(['name' => $name, 'client_name' => 'Klient', 'manager_id' => $managerUser->ID, 'modules' => ['review', 'content']], $admin);
	$revision = ReviewRevision::create([
		'project_id' => $project->id, 'number' => 1, 'title' => 'Rewizja 1', 'status' => ReviewRevisionStatus::Draft,
		'figma_url' => 'https://www.figma.com/proto/ZZtestNoAccess0000000000/Brak-dostepu?node-id=1-2',
		'figma_file_key' => 'ZZtestNoAccess0000000000', 'created_by' => $admin->ID,
	]);
	$path = 'figma/' . $project->uuid . '/review-links.png';
	Storage::disk('fmi')->put($path, $png);
	$frame = FigmaFrame::query()->create(['project_id' => $project->id, 'file_key' => 'ZZtestNoAccess0000000000', 'node_id' => '1:2', 'node_name' => 'Ekran', 'width' => $pngWidth, 'height' => $pngHeight, 'document' => ['id' => '1:2', 'children' => []], 'fetched_at' => now()]);
	FigmaPreview::query()->create(['project_id' => $project->id, 'frame_id' => $frame->id, 'file_key' => 'ZZtestNoAccess0000000000', 'node_id' => '1:2', 'node_name' => 'Ekran', 'position' => 0, 'format' => 'png', 'path' => $path, 'mime_type' => 'image/png', 'width' => $pngWidth, 'height' => $pngHeight]);
	$screen = ReviewScreen::query()->create(['project_id' => $project->id, 'revision_id' => $revision->id, 'figma_frame_id' => $frame->id, 'file_key' => 'ZZtestNoAccess0000000000', 'node_id' => '1:2', 'name' => 'Ekran ' . $name, 'width' => $pngWidth, 'height' => $pngHeight, 'surface_confirmed' => true, 'created_by' => $admin->ID]);
	$thread = $comments->create($project, ['body' => 'Uwaga zespołu ' . $name, 'context' => 'Nagłówek'], $managerUser);

	return [$project, $screen, $thread];
};

[$r, $rs, $rt] = $setup('TEST linki R ' . $manifest['suffix'], $manager);
[$f, $fs, $ft] = $setup('TEST linki F ' . $manifest['suffix'], $admin);
$manifest['projects']['links_r'] = $r->id;
$manifest['projects']['links_f'] = $f->id;

$in = now()->addDays(30);
$tokens = [
	'full' => $links->generateReview($r, $manager, $in, 'Pełny', true, true),
	'readonly' => $links->generateReview($r, $manager, $in, 'Podgląd', false, false),
	'noapprove' => $links->generateReview($r, $manager, $in, 'Bez akceptacji', true, false),
	'code' => $links->generateReview($r, $manager, $in, 'Z kodem', true, false, 'sekret-123'),
	'code2' => $links->generateReview($r, $manager, $in, 'Z kodem 2', true, false, 'sekret-456'),
	'expired' => $links->generateReview($r, $manager, $in, 'Wygasły', true, false),
	'revoked' => $links->generateReview($r, $manager, $in, 'Odwołany', true, false),
	'live' => $links->generateReview($r, $manager, $in, 'Do odwołania', true, false),
	'expiring' => $links->generateReview($r, $manager, $in, 'Do wygaszenia', true, false),
	'foreign' => $links->generateReview($f, $admin, $in, 'Obcy', true, true),
	'content' => $links->generate($r, $manager, $in, 'Treści'),
];
$tokens['expired']->access->forceFill(['expires_at' => now()->subMinute()])->save();
$links->revoke($tokens['revoked']->access, $manager);

file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$path = '/tmp/fmi-review-links.json';
touch($path);
chmod($path, 0600);
file_put_contents($path, json_encode([
	'r' => $r->id, 'f' => $f->id, 'r_name' => $r->name, 'f_name' => $f->name,
	'rs' => $rs->id, 'fs' => $fs->id, 'rt' => $rt->id, 'ft' => $ft->id,
	'tokens' => array_map(fn ($issued) => $issued->token, $tokens),
	'access' => array_map(fn ($issued) => $issued->access->id, $tokens),
]));
echo "Linki recenzji: dane gotowe.\n";
