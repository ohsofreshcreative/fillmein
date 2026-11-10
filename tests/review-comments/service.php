<?php

/**
 * ReviewCommentService / ReviewScreenService — reguły kontekstu, pinezek i numeracji (na danych z fixtures.php,
 * w transakcji wycofywanej). Dodatkowo: parser linku ekranu i potwierdzenie powierzchni.
 */

use App\Models\FigmaFrame;
use App\Models\FigmaPreview;
use App\Models\Project;
use App\Models\ReviewThread;
use App\Services\ReviewCommentService;
use App\Services\ReviewScreenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$ids = json_decode((string) file_get_contents('/tmp/fmi-review-comments.json'), true);
$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$member = get_user_by('id', (int) $manifest['users']['member']);
$pass = 0;
$fail = 0;
$check = function (bool $ok, string $label) use (&$pass, &$fail) {
	$ok ? $pass++ : $fail++;
	echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
};
$rejects = function (callable $fn, string $field) {
	try {
		$fn();

		return false;
	} catch (ValidationException $e) {
		return array_key_exists($field, $e->errors());
	}
};

$check(ReviewScreenService::target('https://www.figma.com/design/abcdefghij12/X?node-id=12-34') === ['file_key' => 'abcdefghij12', 'node_id' => '12:34'], 'Link ekranu /design z node-id');
$check(ReviewScreenService::target('https://www.figma.com/proto/abcdefghij12/X?node-id=1-2&starting-point-node-id=5%3A6') === ['file_key' => 'abcdefghij12', 'node_id' => '1:2'], 'Link /proto: ekran = node-id, nie starting-point');
$check(ReviewScreenService::target('https://www.figma.com/design/abcdefghij12/X') === null, 'Bez node-id → brak ekranu');
$check(ReviewScreenService::target('https://evil.example/design/abcdefghij12/X?node-id=1-2') === null, 'Obca domena → brak ekranu');

DB::beginTransaction();
try {
	$frame = FigmaFrame::query()->create(['project_id' => $ids['l'], 'file_key' => 'TESTkey00000', 'node_id' => '7:7', 'width' => 400, 'height' => 800, 'fetched_at' => now()]);
	$check(ReviewScreenService::surfaceConfirmed($frame) === false, 'Powierzchnia: brak obrazu → niepotwierdzona');
	$preview = FigmaPreview::query()->create(['project_id' => $ids['l'], 'frame_id' => $frame->id, 'file_key' => 'TESTkey00000', 'node_id' => '7:7', 'position' => 0, 'width' => 400, 'height' => 800]);
	$check(ReviewScreenService::surfaceConfirmed($frame) === true, 'Powierzchnia: obraz = granice frame\'u → potwierdzona');
	$preview->update(['width' => 440, 'height' => 860]);
	$check(ReviewScreenService::surfaceConfirmed($frame->fresh()) === false, 'Powierzchnia: obraz większy (treść poza granicami) → niepotwierdzona');

	$project = Project::query()->findOrFail($ids['l']);
	$service = app(ReviewCommentService::class);
	$t1 = $service->create($project, ['body' => 'Pierwsza <b>uwaga</b>', 'screen_id' => $ids['s1'], 'x' => 0.25, 'y' => 0.5], $member);
	$check($t1->number === 1 && $t1->hasPin() && $t1->pin_reference === 'frame_bounds_v1' && $t1->context === null, 'Pinezka na potwierdzonym ekranie: numer 1, układ frame_bounds_v1');
	$check($t1->comments()->first()->body === 'Pierwsza <b>uwaga</b>' && $t1->comments()->first()->author_user_id === $member->ID, 'Treść zapisana bez zmian (escapowanie przy wyświetlaniu), autor');
	$check($t1->revision_id === $project->reviewRevisions()->first()->id && $t1->figma_version !== null, 'Powiązanie z bieżącą rewizją i wersją pliku ekranu');
	$t2 = $service->create($project, ['body' => 'Do ekranu', 'screen_id' => $ids['s1']], $member);
	$check($t2->number === 2 && ! $t2->hasPin(), 'Uwaga do ekranu bez pinezki: numer 2');
	$t3 = $service->create($project, ['body' => 'Kontekst', 'context' => 'Koszyk'], $member);
	$check($t3->number === 3 && $t3->screen_id === null && $t3->context === 'Koszyk' && ! $t3->hasPin(), 'Uwaga do opisanego kontekstu: bez ekranu i pinezki');
	$check($rejects(fn () => $service->create($project, ['body' => 'x', 'screen_id' => $ids['s3'], 'x' => 0.5, 'y' => 0.5], $member), 'x'), 'Pinezka na niepotwierdzonym ekranie → odrzucona');
	$check($rejects(fn () => $service->create($project, ['body' => 'x', 'context' => 'Koszyk', 'x' => 0.5, 'y' => 0.5], $member), 'x'), 'Pinezka bez ekranu → odrzucona (bez wymyślania pozycji)');
	$check($rejects(fn () => $service->create($project, ['body' => 'x', 'screen_id' => $ids['foreign_screen']], $member), 'screen_id'), 'Ekran obcego projektu → odrzucony');
	$check($rejects(fn () => $service->create($project, ['body' => 'x'], $member), 'context'), 'Bez ekranu i kontekstu → odrzucona');
	$t4 = $service->create($project, ['body' => 'Ekran + kontekst', 'screen_id' => $ids['s3'], 'context' => 'ignorowany'], $member);
	$check($t4->context === null && $t4->screen_id === $ids['s3'] && ! $t4->hasPin(), 'Niepotwierdzony ekran: uwaga do ekranu bez pinezki (kontekst pominięty)');
	$check(ReviewThread::query()->where('project_id', $ids['l'])->pluck('number')->sort()->values()->all() === [1, 2, 3, 4], 'Numeracja ciągła w projekcie');
} finally {
	DB::rollBack();
}

echo "SERWIS: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
