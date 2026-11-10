<?php

/**
 * FigmaQueueService: podstrony oczekujące na frame, blokada równoległego przetwarzania, błędy jako JSON-owy wynik.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Page;
use App\Models\Project;
use App\Services\FigmaFrameService;
use App\Services\FigmaQueueService;
use App\Services\PageService;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$admin = get_userdata((int) $manifest['users']['admin']);
$project = Project::query()->findOrFail((int) $manifest['projects']['a']);
$queue = app(FigmaQueueService::class);
$pages = app(PageService::class);

$plain = $pages->create($project, ['name' => 'Bez Figmy kolejka'], $admin);
$linked = $pages->create($project, ['name' => 'Z Figmą kolejka', 'figma_frame_url' => 'https://www.figma.com/design/abcdefghij12/A?node-id=9-9'], $admin);

Harness::test('Oczekujące: tylko podstrony z linkiem bez pobranego frame\'u', function () use ($queue, $project, $plain, $linked) {
	$pending = $queue->pending($project, [$plain, $linked]);

	if (! app(FigmaFrameService::class)->isConfigured()) {
		Harness::same([], $pending, 'bez konfiguracji Figmy kolejka jest pusta');

		return;
	}

	Harness::same([$linked->id], $pending, 'podstrona z linkiem');
});

Harness::test('Błąd Figmy wraca jako wynik, nie wyjątek', function () use ($queue, $plain, $admin) {
	$result = $queue->fetch($plain, $admin);

	Harness::same('error', $result['status'], 'status');
	Harness::assert(str_contains($result['message'], 'linku do frame'), 'komunikat: ' . $result['message']);
	Harness::same('error', $queue->import($plain, $admin)['status'], 'import bez linku');
	Harness::same(false, get_option('fmi_figma_queue_' . $plain->id), 'blokada zwolniona po błędzie');
});

Harness::test('Blokada: równoległe przetwarzanie tej samej podstrony', function () use ($queue, $plain, $admin) {
	add_option('fmi_figma_queue_' . $plain->id, time(), '', false);
	Harness::same('running', $queue->fetch($plain, $admin)['status'], 'trwa');

	update_option('fmi_figma_queue_' . $plain->id, time() - 3600, false);
	Harness::same('error', $queue->fetch($plain, $admin)['status'], 'przeterminowana blokada jest przejmowana');
	delete_option('fmi_figma_queue_' . $plain->id);
});

file_put_contents('/tmp/fmi-figma-queue-ids.json', json_encode(['plain' => $plain->id, 'linked' => $linked->id]));

exit(Harness::summary('Figma — kolejka'));
