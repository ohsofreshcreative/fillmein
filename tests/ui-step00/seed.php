<?php

/**
 * Krok 0 (audyt UI) — zasiew podstrony A (dane testowe etapu 03) w stanie przyciętego drzewa.
 *
 * Fixture etapu 05 przycinamy płytszym limitem głębokości, żeby frame miał węzły `truncated`,
 * i dodajemy sekcję z Figmy spoza pobranej części — podgląd importu pokazuje ją jako `unverified`.
 * Wszystko znika kaskadowo z projektem testowym (tests/stage03/teardown.php). Bez sieci.
 */

use App\Models\FigmaFrame;
use App\Models\Page;
use App\Models\Section;
use App\Services\FigmaFrameService;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);

if (! is_array($manifest)) {
	fwrite(STDERR, "BLOCKED: brak manifestu danych testowych etapu 03.\n");
	exit(2);
}

$page = Page::query()->findOrFail((int) $manifest['pages']['a']);
$document = json_decode((string) file_get_contents(dirname(__DIR__) . '/stage05/fixtures/nodes-landing.json'), true)['nodes']['10:1']['document'];
$document['id'] = '1:2'; // węzeł z linku podstrony A w tests/stage03/setup.php

$hasTruncated = function (array $node) use (&$hasTruncated): bool {
	if (! empty($node['truncated'])) {
		return true;
	}

	foreach ((array) ($node['children'] ?? []) as $child) {
		if (is_array($child) && $hasTruncated($child)) {
			return true;
		}
	}

	return false;
};

$pruned = null;

foreach ([3, 2, 1] as $depth) {
	$count = 0;
	$candidate = FigmaFrameService::prune($document, $depth, $count, 5000);

	if ($hasTruncated($candidate)) {
		$pruned = $candidate;
		break;
	}
}

if ($pruned === null) {
	fwrite(STDERR, "FAIL: fixture nie daje przyciętego drzewa.\n");
	exit(1);
}

FigmaFrame::query()->updateOrCreate(
	['project_id' => $page->project_id, 'file_key' => 'abcdefghij12', 'node_id' => '1:2'],
	[
		'node_name' => $document['name'],
		'node_type' => 'FRAME',
		'file_version' => '5555555555',
		'width' => 1440,
		'height' => 4200,
		'document' => $pruned,
		'nodes_count' => $count,
		'fetched_at' => now(),
	],
);

Section::query()->create([
	'project_id' => $page->project_id,
	'page_id' => $page->id,
	'name' => 'Sekcja poza pobraną częścią',
	'source' => 'figma',
	'figma_node_id' => '999:999',
	'figma_node_name' => 'Sekcja poza pobraną częścią',
	'position' => 99,
]);

fwrite(STDOUT, "Zasiew krok 0: podstrona A={$page->id}, głębokość {$depth}, warstw {$count}, drzewo przycięte, sekcja unverified.\n");
