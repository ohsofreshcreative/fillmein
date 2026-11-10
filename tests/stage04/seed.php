<?php

/**
 * Etap 04 — zasiew cache frame'u dla podstrony A z danych testowych etapu 03 (tests/stage03/setup.php).
 *
 * Używa prawdziwego FigmaFrameService z atrapą API (fixture), bo testy HTTP idą z osobnego procesu
 * i potrzebują zatwierdzonych danych. Katalog podglądów zapisujemy w manifeście — usuwa go cleanup.php.
 */

require __DIR__ . '/_fake.php';

use App\Models\Page;
use App\Services\ActivityLogger;
use App\Services\FigmaFrameService;

$manifestPath = '/tmp/fmi-stage03-fixtures.json';
$manifest = json_decode((string) @file_get_contents($manifestPath), true);

if (! is_array($manifest)) {
	fwrite(STDERR, "BLOCKED: brak manifestu danych testowych etapu 03.\n");
	exit(2);
}

$admin = get_userdata((int) $manifest['users']['admin']);
wp_set_current_user($admin->ID);

$page = Page::query()->findOrFail((int) $manifest['pages']['a']);
$key = 'abcdefghij12'; // klucz z linku podstrony A w tests/stage03/setup.php

$fake = (new FakeFigma())
	->on('~^https://api\.figma\.com/v1/files/' . $key . '/nodes\?~', [FakeFigma::fixture('nodes-frame.json'), 200])
	->on('~^https://api\.figma\.com/v1/images/' . $key . '\?~', [FakeFigma::fixture('images.json'), 200])
	->on('~^https://figma-alpha-api\.s3\.us-west-2\.amazonaws\.com/images/~', [FakeFigma::png(24, 16), 200]);

$result = (new FigmaFrameService($fake->client(), app(ActivityLogger::class)))->fetch($page, $admin, true);

$manifest['figma_dirs'] = ['figma/' . $page->project->uuid];
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

fwrite(STDOUT, sprintf("Cache frame'u zasiany: podstrona A=%d, węzeł %s, podglądy %d.\n", $page->id, $result['frame']->node_id, $result['previews']));

exit($result['previews'] === 4 ? 0 : 1);
