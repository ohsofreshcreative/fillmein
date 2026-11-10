<?php

/**
 * Etap 04 — test integracyjny LIVE z prawdziwym Figma API (kryterium C4).
 *
 * Wymaga:
 * - tokenu: stała FMI_FIGMA_TOKEN w wp-config.php albo zmienna środowiskowa FMI_FIGMA_TOKEN,
 * - linku do frame'u testowego: zmienna środowiskowa FMI_FIGMA_LIVE_URL (adres z node-id).
 *
 * Bez nich kończy się kodem 3 (NOT_RUN) — nie zgadujemy danych. Wartości tokenu nie wypisujemy.
 * Zapis w bazie działa w transakcji wycofywanej; pliki podglądów są usuwane po teście.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Integrations\Figma\FigmaClient;
use App\Integrations\Figma\FigmaException;
use App\Integrations\Figma\FigmaUrl;
use App\Models\FigmaFrame;
use App\Services\FigmaFrameService;
use App\Services\PageService;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$url = (string) getenv('FMI_FIGMA_LIVE_URL');

if (FigmaClient::configuredToken() === null) {
	fwrite(STDOUT, "NOT_RUN: brak tokenu FMI_FIGMA_TOKEN (wp-config.php lub zmienna środowiskowa).\n");
	exit(3);
}

if (! FigmaUrl::isFrame($url)) {
	fwrite(STDOUT, "NOT_RUN: ustaw FMI_FIGMA_LIVE_URL na link do frame'u (z node-id).\n");
	exit(3);
}

$admin = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;
wp_set_current_user($admin->ID);

$ok = [Harness::class, 'assert'];
$dir = null;

DB::beginTransaction();

try {
	$project = app(ProjectService::class)->create(['name' => 'TEST etap04 live'], $admin);
	$dir = 'figma/' . $project->uuid;
	$page = app(PageService::class)->create($project, ['name' => 'Live', 'figma_frame_url' => $url], $admin);
	$service = app(FigmaFrameService::class);

	Harness::test('C4 live: pobranie wskazanego frame\'u i podglądów', function () use ($service, $page, $admin, $ok) {
		$result = $service->fetch($page, $admin, true);
		$frame = $result['frame'];

		$ok($frame->node_id === FigmaUrl::nodeId($page->figma_frame_url), 'Węzeł zgodny z linkiem');
		$ok($frame->file_version !== null && $frame->fetched_at !== null, 'Wersja i czas pobrania');
		$ok($result['previews'] >= 1 && $result['previews_failed'] === 0, 'Podglądy pobrane: ' . $result['previews'] . ', błędy: ' . $result['previews_failed']);

		foreach ($frame->previews as $preview) {
			$ok(Storage::disk('fmi')->exists($preview->path), 'Plik podglądu w prywatnym cache');
		}

		fwrite(STDOUT, sprintf("        frame: %s (%s), warstw: %d, podglądów: %d\n", $frame->node_name, $frame->node_type, $frame->nodes_count, $result['previews']));
	});

	Harness::test('C4 live: ponowne pobranie w TTL z cache', function () use ($service, $page, $admin, $ok) {
		$ok($service->fetch($page, $admin)['from_cache'] === true, 'from_cache');
	});

	// Figma dla brakującego węzła zwraca 200 z `nodes: {"<id>": null}` — błąd zgłasza serwis, nie klient.
	Harness::test('C4 live: nieistniejący węzeł → kontrolowany błąd NOT_FOUND bez zapisu', function () use ($service, $project, $url, $admin, $ok) {
		$missing = app(PageService::class)->create($project, [
			'name' => 'Live brak węzła',
			'figma_frame_url' => 'https://www.figma.com/design/' . FigmaUrl::fileKey($url) . '/Test?node-id=999999-999999',
		], $admin);
		$error = null;

		try {
			$service->fetch($missing, $admin, true);
		} catch (FigmaException $e) {
			$error = $e;
		}

		$ok($error !== null, 'Brak błędu dla nieistniejącego węzła');
		$ok($error?->kind === FigmaException::NOT_FOUND, 'Rodzaj błędu: ' . ($error?->kind ?? 'brak'));
		$ok(FigmaFrame::query()->where('project_id', $project->id)->where('node_id', '999999:999999')->doesntExist(), 'Nic nie zapisano dla brakującego węzła');
	});
} finally {
	DB::rollBack();

	if ($dir) {
		Storage::disk('fmi')->deleteDirectory($dir);
	}
}

exit(Harness::summary('ETAP 04 — Figma LIVE'));
