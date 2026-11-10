<?php

/**
 * Etap 10 — dwie synchronizacje pod rząd na PRAWDZIWYM frame'ie GreenQ (FMI_FIGMA_TOKEN + FMI_FIGMA_LIVE_URL).
 * Bez nich kod 3 (NOT_RUN). Zapisy w transakcji wycofywanej; pliki podglądów usuwane po teście.
 * Wartości tokenu i linku nie wypisujemy; z treści warstw wypisujemy tylko liczby.
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

$dir = null;

DB::beginTransaction();

try {
	$project = app(ProjectService::class)->create(['name' => 'TEST etap10 live'], $admin);
	$dir = 'figma/' . $project->uuid;
	$page = app(PageService::class)->create($project, ['name' => 'Live', 'figma_frame_url' => $url], $admin);
	$import = app(FigmaImportService::class);
	$frames = app(FigmaFrameService::class);
	$rows = fn () => [
		Section::query()->where('page_id', $page->id)->orderBy('id')->get()->map->getAttributes()->all(),
		Field::query()->where('project_id', $project->id)->orderBy('id')->get()->map->getAttributes()->all(),
	];

	Harness::test('Live: pierwsza synchronizacja zakłada strukturę', function () use ($frames, $page, $admin, $import) {
		$frames->fetch($page, $admin, true);
		$plan = $import->plan($page);

		Harness::assert($plan['counts']['create'] > 0, 'Nowe elementy');
		Harness::same(false, $plan['truncated'], 'Frame pobrany w całości');

		$counts = $import->apply($page, $admin, $plan['fingerprint']);
		fwrite(STDOUT, sprintf("        pierwsza: nowe %d\n", $counts['create']));
	});

	Harness::test('Live: druga synchronizacja po ponownym pobraniu daje 0 zmian', function () use ($frames, $page, $admin, $import, $rows) {
		$before = $rows();

		$frames->fetch($page, $admin, true);
		$plan = $import->plan($page);

		Harness::same(false, $plan['has_changes'], '0 zmian w podglądzie');
		Harness::same([], $plan['defaults'], 'Nic do wyboru');
		Harness::same(0, $plan['counts']['create'] + $plan['counts']['update'] + $plan['counts']['restore'] + $plan['counts']['archive'], 'Liczniki zero');

		$counts = $import->apply($page, $admin, $plan['fingerprint']);

		Harness::same(0, $counts['create'] + $counts['update'] + $counts['restore'] + $counts['archive'], 'Zero operacji');
		$after = $rows();

		// Tylko nazwy kolumn: treść warstw z pliku klienta nie trafia na wyjście.
		foreach ([0, 1] as $set) {
			foreach ($before[$set] as $index => $row) {
				$columns = array_keys(array_diff_assoc($row, $after[$set][$index] ?? []));

				if ($columns !== []) {
					fwrite(STDOUT, '        różnica w kolumnach: ' . implode(', ', $columns) . "\n");
				}
			}
		}

		Harness::assert($before === $after, 'Identyczne wiersze');
		fwrite(STDOUT, sprintf("        druga: bez zmian %d\n", $counts['unchanged']));
	});
} finally {
	DB::rollBack();

	if ($dir) {
		Storage::disk('fmi')->deleteDirectory($dir);
	}
}

exit(Harness::summary('ETAP 10 — Figma LIVE'));
