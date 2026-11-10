<?php

/**
 * Etap 05 — analiza i import na PRAWDZIWYM frame'ie z Figma API (FMI_FIGMA_TOKEN + FMI_FIGMA_LIVE_URL).
 * Bez nich kod 3 (NOT_RUN). Zapisy w transakcji wycofywanej; pliki podglądów usuwane po teście.
 * Wartości tokenu nie wypisujemy; z treści warstw wypisujemy tylko liczby.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Integrations\Figma\FigmaClient;
use App\Integrations\Figma\FigmaUrl;
use App\Models\Field;
use App\Models\Section;
use App\Services\FigmaFrameService;
use App\Services\FigmaImportService;
use App\Services\PageService;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$url = (string) getenv('FMI_FIGMA_LIVE_URL');

if (FigmaClient::configuredToken() === null || ! FigmaUrl::isFrame($url)) {
	fwrite(STDOUT, "NOT_RUN: brak tokenu FMI_FIGMA_TOKEN lub FMI_FIGMA_LIVE_URL.\n");
	exit(3);
}

$admin = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;
wp_set_current_user($admin->ID);

$ok = [Harness::class, 'assert'];
$dir = null;

DB::beginTransaction();

try {
	$project = app(ProjectService::class)->create(['name' => 'TEST etap05 live'], $admin);
	$dir = 'figma/' . $project->uuid;
	$page = app(PageService::class)->create($project, ['name' => 'Live', 'figma_frame_url' => $url], $admin);
	$import = app(FigmaImportService::class);

	Harness::test('Live: pobranie frame\'u (drzewo z text_style) i analiza warstw', function () use ($page, $admin, $import, $ok) {
		$frame = app(FigmaFrameService::class)->fetch($page, $admin, true)['frame'];
		$json = json_encode($frame->document);
		$plan = $import->plan($page);
		$stats = $plan['analysis']['stats'];

		$ok(str_contains($json, '"text_style"'), 'Style tekstu w cache');
		$ok($stats['sections'] >= 1 && $stats['fields'] >= 1, 'Wykryto sekcje i pola');
		$ok($plan['counts']['create'] === $stats['sections'] + $stats['fields'] + $stats['groups'] + $stats['repeaters'], 'Wszystko jako nowe');

		fwrite(STDOUT, sprintf("        warstw: %d, sekcje: %d, pola: %d, grupy: %d, repeatery: %d, wykluczone: %d\n",
			$frame->nodes_count, $stats['sections'], $stats['fields'], $stats['groups'], $stats['repeaters'], $stats['excluded']));
	});

	Harness::test('Live: import i ponowny import bez duplikatów (te same UUID)', function () use ($page, $admin, $import, $ok) {
		$import->apply($page, $admin, $import->plan($page)['fingerprint']);
		$sections = Section::query()->where('page_id', $page->id);
		$uuids = fn () => Field::query()->whereIn('section_id', (clone $sections)->select('id'))->orderBy('id')->pluck('uuid')->all();
		$before = $uuids();

		$plan = $import->plan($page);
		$ok(! $plan['has_changes'], 'Drugi podgląd bez zmian');
		$counts = $import->apply($page, $admin, $plan['fingerprint']);

		$ok($counts['create'] + $counts['update'] + $counts['archive'] + $counts['restore'] === 0, 'Zero operacji');
		$ok($before === $uuids() && count($before) > 0, 'Identyczne pola');
	});
} finally {
	DB::rollBack();

	if ($dir) {
		Storage::disk('fmi')->deleteDirectory($dir);
	}
}

exit(Harness::summary('ETAP 05 — Figma LIVE'));
