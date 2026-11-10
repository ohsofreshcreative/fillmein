<?php

/**
 * Etap 05 — zasiew cache frame'u podstrony A (dane testowe etapu 03) fixture'em `nodes-landing.json`.
 * Testy HTTP idą z osobnego procesu, więc dane muszą być zatwierdzone. Wiersz cache znika kaskadowo
 * razem z projektem testowym (tests/stage03/teardown.php). Bez sieci i bez plików podglądów.
 */

use App\Models\FigmaFrame;
use App\Models\Page;
use App\Services\FigmaFrameService;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);

if (! is_array($manifest)) {
	fwrite(STDERR, "BLOCKED: brak manifestu danych testowych etapu 03.\n");
	exit(2);
}

$page = Page::query()->findOrFail((int) $manifest['pages']['a']);
$document = json_decode((string) file_get_contents(__DIR__ . '/fixtures/nodes-landing.json'), true)['nodes']['10:1']['document'];
$document['id'] = '1:2'; // węzeł z linku podstrony A w tests/stage03/setup.php
$count = 0;

FigmaFrame::query()->updateOrCreate(
	['project_id' => $page->project_id, 'file_key' => 'abcdefghij12', 'node_id' => '1:2'],
	[
		'node_name' => $document['name'],
		'node_type' => 'FRAME',
		'file_version' => '5555555555',
		'width' => 1440,
		'height' => 4200,
		'document' => FigmaFrameService::prune($document, 8, $count, 5000),
		'nodes_count' => $count,
		'fetched_at' => now(),
	],
);

fwrite(STDOUT, "Cache frame'u zasiany: podstrona A={$page->id}, warstw {$count}.\n");
