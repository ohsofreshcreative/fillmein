<?php

/**
 * Etap 05a — LIVE: najdłuższe ID warstwy w prawdziwym frame'ie z Figma API (FMI_FIGMA_TOKEN + FMI_FIGMA_LIVE_URL)
 * i zapis pełnych ID przy imporcie. Frame pobierany przez FigmaClient z cache etapu 04 (TTL), zapisy w transakcji
 * wycofywanej, pliki podglądów usuwane po teście. Bez zmiennych kod 3 (NOT_RUN).
 * Wypisujemy wyłącznie długości i liczby — nie token, nie link i nie treść ID.
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

/** Wszystkie ID warstw z drzewa frame'u. */
$collectIds = function (array $node) use (&$collectIds): array {
	$ids = isset($node['id']) && is_string($node['id']) ? [$node['id']] : [];

	foreach ($node['children'] ?? [] as $child) {
		$ids = array_merge($ids, $collectIds($child));
	}

	return $ids;
};

$maxLength = fn (array $ids): int => $ids === [] ? 0 : max(array_map('strlen', $ids));

DB::beginTransaction();

try {
	$project = app(ProjectService::class)->create(['name' => 'TEST etap05a live'], $admin);
	$dir = 'figma/' . $project->uuid;
	$page = app(PageService::class)->create($project, ['name' => 'Live', 'figma_frame_url' => $url], $admin);
	$import = app(FigmaImportService::class);

	Harness::test('Live: najdłuższe ID warstwy we frame\'ie mieści się w 255 znakach', function () use ($page, $admin, $collectIds, $maxLength, $ok) {
		$frame = app(FigmaFrameService::class)->fetch($page, $admin)['frame'];
		$ids = array_unique($collectIds($frame->document));
		$longest = $maxLength($ids);

		$ok(count($ids) > 1, 'Drzewo frame\'u ma warstwy');
		$ok($longest <= 255, "Najdłuższe ID ma {$longest} znaków (> 255)");

		fwrite(STDOUT, sprintf("        warstw (unikalne ID): %d, najdłuższe ID: %d znaków, ID dłuższych niż 64: %d\n",
			count($ids), $longest, count(array_filter($ids, fn ($id) => strlen($id) > 64))));
	});

	Harness::test('Live: import zapisuje pełne ID (bez obcięcia) w fmi_sections i fmi_fields', function () use ($page, $admin, $import, $collectIds, $maxLength, $ok) {
		$plan = $import->plan($page);
		$proposed = [];
		$walk = function (array $fields) use (&$walk, &$proposed) {
			foreach ($fields as $field) {
				$proposed[] = $field['node_id'];
				$walk($field['children']);
			}
		};

		foreach ($plan['analysis']['sections'] as $section) {
			$proposed[] = $section['node_id'];
			$walk($section['fields']);
		}

		$import->apply($page, $admin, $plan['fingerprint']);

		$sections = Section::query()->where('page_id', $page->id);
		$stored = array_merge(
			(clone $sections)->pluck('figma_node_id')->all(),
			Field::query()->whereIn('section_id', (clone $sections)->select('id'))->pluck('figma_node_id')->all(),
		);
		$stored = array_values(array_unique(array_filter($stored)));

		$ok(count($stored) > 0 && count($proposed) > 0, 'Zapisano sekcje i pola');
		$ok(array_diff($proposed, $stored) === [] && array_diff($stored, $proposed) === [], 'Zapisane ID = ID z analizy (pełne, bez obcięcia)');
		$ok($maxLength($stored) === $maxLength($proposed), 'Najdłuższe zapisane ID = najdłuższe z analizy');

		fwrite(STDOUT, sprintf("        zapisanych ID sekcji/pól: %d, najdłuższe zapisane: %d znaków\n", count($stored), $maxLength($stored)));
	});
} finally {
	DB::rollBack();

	if ($dir) {
		Storage::disk('fmi')->deleteDirectory($dir);
	}
}

exit(Harness::summary('ETAP 05a — Figma LIVE'));
