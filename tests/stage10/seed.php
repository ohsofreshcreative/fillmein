<?php

/**
 * Etap 10 — trwałe dane testów HTTP i audytu UI: podstrona A po imporcie v1 z frame'em v2 w cache,
 * podstrona z frame'em przyciętym, podstrona ze zmianą typu pola z treścią. Znikają z teardown etapu 03.
 */

use App\Models\Field;
use App\Models\FigmaFrame;
use App\Models\Page;
use App\Models\Project;
use App\Models\Section;
use App\Services\ActivityLogger;
use App\Services\ClientAccessService;
use App\Services\ClientEditorService;
use App\Services\ContentService;
use App\Services\FigmaFrameService;
use App\Services\FigmaImportService;
use App\Services\PageService;
use App\Services\ProjectService;
use App\Support\Actor;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);

if (! is_array($manifest)) {
	fwrite(STDERR, "BLOCKED: brak manifestu danych testowych etapu 03.\n");
	exit(2);
}

$admin = get_userdata((int) $manifest['users']['admin']);
$load = fn (string $name) => json_decode((string) file_get_contents(__DIR__ . "/fixtures/{$name}.json"), true);
$prune = function (array $document, int $max = 5000): array {
	$count = 0;

	return FigmaFrameService::prune($document, 8, $count, $max);
};
$retarget = function (array $document, string $node) {
	$document['id'] = $node;

	return $document;
};

$project = Project::query()->findOrFail((int) $manifest['projects']['a']);
$pageA = Page::query()->findOrFail((int) $manifest['pages']['a']);
$service = app(FigmaImportService::class);
$content = app(ContentService::class);
$pages = app(PageService::class);
$actor = Actor::user($admin);

$cache = fn (string $node, array $tree) => FigmaFrame::query()->updateOrCreate(
	['project_id' => $project->id, 'file_key' => 'abcdefghij12', 'node_id' => $node],
	['node_name' => 'Landing', 'node_type' => 'FRAME', 'file_version' => (string) random_int(1000, 9999), 'width' => 1440, 'height' => 4200, 'document' => $tree, 'nodes_count' => 60, 'fetched_at' => now()],
);
$field = fn (Page $page, string $node) => Field::query()->whereIn('section_id', Section::query()->where('page_id', $page->id)->select('id'))->where('figma_node_id', $node)->firstOrFail();
$imported = function (Page $page, string $node) use ($cache, $prune, $retarget, $load, $service, $admin) {
	$cache($node, $prune($retarget($load('frame-v1'), $node)));
	$service->apply($page, $admin, $service->plan($page)['fingerprint']);
};

$imported($pageA, '1:2');

$filled = app(ClientEditorService::class)->saveValue($admin, $field($pageA, '10:302'), null, ['value' => 'Treść od klienta'], 0);
$cache('1:2', $prune($retarget($load('frame-v2'), '1:2')));

$url = fn (string $node) => 'https://www.figma.com/design/abcdefghij12/Plik?node-id=' . str_replace(':', '-', $node);

$cut = $pages->create($project, ['name' => 'Frame przycięty', 'figma_frame_url' => $url('1:3')], $admin);
$imported($cut, '1:3');
$cache('1:3', $prune($retarget($load('frame-v2'), '1:3'), 12));

$risk = $pages->create($project, ['name' => 'Zmiana typu', 'figma_frame_url' => $url('1:4')], $admin);
$imported($risk, '1:4');
$content->save($field($risk, '10:201'), 'Tytuł klienta', 0, $actor);
$document = $retarget($load('frame-v1'), '1:4');
$patch = function (array &$node) use (&$patch) {
	if (($node['id'] ?? null) === '10:201') {
		$node['characters'] = "Dwie\nlinie";
	}

	foreach ($node['children'] ?? [] as $index => $child) {
		$patch($node['children'][$index]);
	}
};
$patch($document);
$cache('1:4', $prune($document));

// Wiele wpisów: stronicowanie dziennika i przeglądu.
for ($i = 0; $i < 35; $i++) {
	app(ActivityLogger::class)->log('section.updated', $actor, $project, null, ['name' => 'Wpis ' . $i]);
}

$token = app(ClientAccessService::class)->generate($project, $admin)->token;

$path = '/tmp/fmi-stage10-ids.json';
touch($path);
chmod($path, 0600);
file_put_contents($path, json_encode([
	'token' => $token,
	'pages' => ['cut' => $cut->id, 'risk' => $risk->id],
	'value' => $filled->id,
	'foreign_value' => null,
]));

fwrite(STDOUT, "Dane etapu 10 zasiane.\n");
