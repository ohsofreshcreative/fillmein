<?php

/**
 * Etap 05a — ID warstw Figmy o długości 200 znaków (jak w instancjach komponentów, `I10:101;1:1;…`):
 * import fixture'a etapu 05 zapisuje pełne ID w fmi_sections, fmi_fields i fmi_figma_node_rules,
 * a down() migracji poszerzającej odmawia, gdy w bazie jest wartość dłuższa niż 64 znaki.
 *
 * Zapisy działają w transakcji wycofywanej na końcu (DDL nie jest wykonywany — down() testujemy
 * przez assertCanShrink(), bez rollbacku migracji).
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Integrations\Figma\FigmaUrl;
use App\Models\Field;
use App\Models\FigmaFrame;
use App\Models\FigmaNodeRule;
use App\Models\Section;
use App\Services\FigmaFrameService;
use App\Services\FigmaImportService;
use App\Services\PageService;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;

$admin = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;

if (! $admin) {
	fwrite(STDERR, "BLOCKED: brak użytkownika administratora do wykonania testów.\n");
	exit(2);
}

wp_set_current_user($admin->ID);

$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];
$throws = [Harness::class, 'throws'];

const LONG_ID_LENGTH = 200;

/** ID instancji o dokładnie 200 znakach (`I<seed>;1:1;11:1;…`), poprawne wg FigmaUrl::isLayerId(). */
$longId = function (string $seed): string {
	$id = 'I' . $seed;
	$fill = LONG_ID_LENGTH - strlen($id);
	$segments = intdiv($fill, 4);
	$extra = $fill % 4;

	for ($i = 0; $i < $segments; $i++) {
		$id .= ';' . str_repeat('1', $i < $extra ? 2 : 1) . ':1';
	}

	return $id;
};

$sectionId = $longId('10:200');
$fieldId = $longId('10:201');
$footerId = $longId('10:900');

/** Zmiana ID warstwy w surowym dokumencie. */
$rename = function (array &$node, string $from, string $to) use (&$rename): bool {
	if (($node['id'] ?? null) === $from) {
		$node['id'] = $to;

		return true;
	}

	foreach ($node['children'] ?? [] as $index => $child) {
		if ($rename($node['children'][$index], $from, $to)) {
			return true;
		}
	}

	return false;
};

$document = json_decode((string) file_get_contents(__DIR__ . '/../stage05/fixtures/nodes-landing.json'), true)['nodes']['10:1']['document'];

foreach (['10:200' => $sectionId, '10:201' => $fieldId, '10:900' => $footerId] as $from => $to) {
	if (! $rename($document, $from, $to)) {
		fwrite(STDERR, "BLOCKED: fixture etapu 05 nie ma warstwy {$from}.\n");
		exit(2);
	}
}

$count = 0;
$pruned = FigmaFrameService::prune($document, 8, $count, 5000);

$migration = require __DIR__ . '/../../database/migrations/2026_10_29_000100_widen_fmi_figma_node_ids.php';

echo "== Etap 05a: ID warstw o długości " . LONG_ID_LENGTH . " znaków ==\n";

Harness::test('Dane testowe: ID mają 200 znaków i przechodzą walidację warstwy (limit 255)', function () use ($sectionId, $fieldId, $footerId, $same, $ok) {
	foreach ([$sectionId, $fieldId, $footerId] as $id) {
		$same(LONG_ID_LENGTH, strlen($id), 'Długość ID');
		$ok(FigmaUrl::isLayerId($id), 'isLayerId akceptuje ID 200 znaków');
	}

	$ok(! FigmaUrl::isLayerId(str_repeat('1', 256)), 'isLayerId odrzuca ponad 255 znaków');
	$ok(! FigmaUrl::isLayerId('I10:1' . str_repeat(';1:1', 64)), 'isLayerId odrzuca ID dłuższe niż 255 znaków');
});

DB::beginTransaction();

try {
	$project = app(ProjectService::class)->create(['name' => 'TEST etap05a'], $admin);
	$page = app(PageService::class)->create($project, [
		'name' => 'Landing',
		'figma_frame_url' => 'https://www.figma.com/design/STAGE05akey01/Landing?node-id=10-1',
	], $admin);

	FigmaFrame::create([
		'project_id' => $project->id, 'file_key' => 'STAGE05akey01', 'node_id' => '10:1', 'node_name' => 'Landing — desktop',
		'node_type' => 'FRAME', 'file_version' => '5555555555', 'nodes_count' => $count, 'document' => $pruned, 'fetched_at' => now(),
	]);

	$service = app(FigmaImportService::class);

	Harness::test('Reguła węzła z ID 200 znaków zapisana w całości (include dla wykluczonej stopki)', function () use ($service, $page, $admin, $footerId, $same) {
		$service->setRule($page, $footerId, 'include', $admin);

		$stored = FigmaNodeRule::query()->where('page_id', $page->id)->value('node_id');
		$same($footerId, $stored, 'node_id reguły');
		$same(LONG_ID_LENGTH, strlen((string) $stored), 'Długość node_id reguły');
	});

	Harness::test('Import: pełne ID 200 znaków w fmi_sections i fmi_fields (także stopka z reguły)', function () use ($service, $page, $admin, $sectionId, $fieldId, $footerId, $same, $ok) {
		$service->apply($page, $admin, $service->plan($page)['fingerprint']);

		$sections = Section::query()->where('page_id', $page->id);
		$fields = Field::query()->whereIn('section_id', (clone $sections)->select('id'));

		$ok((clone $sections)->where('figma_node_id', $sectionId)->exists(), 'Sekcja z pełnym ID');
		$ok((clone $sections)->where('figma_node_id', $footerId)->exists(), 'Sekcja stopki z pełnym ID');
		$ok((clone $fields)->where('figma_node_id', $fieldId)->exists(), 'Pole z pełnym ID');

		$same(LONG_ID_LENGTH, (int) (clone $sections)->max(DB::raw('CHAR_LENGTH(figma_node_id)')), 'Najdłuższe ID sekcji');
		$same(LONG_ID_LENGTH, (int) (clone $fields)->max(DB::raw('CHAR_LENGTH(figma_node_id)')), 'Najdłuższe ID pola');
	});

	Harness::test('Ponowny import bez zmian: zero operacji (pełne ID dopasowuje istniejące elementy)', function () use ($service, $page, $admin, $same) {
		$plan = $service->plan($page);
		$counts = $service->apply($page, $admin, $plan['fingerprint']);

		$same(0, $counts['create'] + $counts['update'] + $counts['archive'] + $counts['restore'], 'Zero operacji');
	});

	Harness::test('down(): odmawia (RuntimeException), gdy w bazie jest ID dłuższe niż 64 znaki', function () use ($migration, $throws) {
		$throws(RuntimeException::class, fn () => $migration->assertCanShrink(), 'Dłuższe ID blokuje cofnięcie', 'Schemat nie został zmieniony');
	});

	Harness::test('down(): komunikat wskazuje kolumny, ale nie zawiera treści ID', function () use ($migration, $sectionId, $fieldId, $ok) {
		$message = '';

		try {
			$migration->assertCanShrink();
		} catch (RuntimeException $e) {
			$message = $e->getMessage();
		}

		$ok(str_contains($message, 'fmi_sections.figma_node_id') && str_contains($message, 'fmi_fields.figma_node_id'), 'Wskazane kolumny');
		$ok(str_contains($message, 'fmi_figma_node_rules.node_id'), 'Wskazana kolumna reguł');
		$ok(! str_contains($message, $sectionId) && ! str_contains($message, $fieldId), 'Bez treści ID');
	});

	Harness::test('down(): po skróceniu wartości do ≤ 64 znaków sprawdzenie przechodzi (nie odmawia zawsze)', function () use ($migration, $page, $ok) {
		// Tylko w transakcji wycofywanej: kolumny z długimi ID zastępujemy krótkimi, unikalnymi wartościami.
		DB::table('fmi_sections')->where('page_id', $page->id)->update(['figma_node_id' => DB::raw("CONCAT('s', id)")]);
		DB::table('fmi_fields')->whereIn('section_id', DB::table('fmi_sections')->where('page_id', $page->id)->select('id'))
			->update(['figma_node_id' => DB::raw("CONCAT('f', id)")]);
		DB::table('fmi_figma_node_rules')->where('page_id', $page->id)->update(['node_id' => DB::raw("CONCAT('r', id)")]);

		$migration->assertCanShrink();
		$ok(true, 'Brak wyjątku');
	});
} finally {
	DB::rollBack();
}

exit(Harness::summary('ETAP 05a — logika'));
