<?php

/**
 * Etap 14 — identyfikatory do macierzy bezpieczeństwa. Bazuje na zasiewie etapu 11 (projekt A) i dokłada obce
 * elementy w projekcie B oraz konto bez `fmi_access`. Tokeny trafiają wyłącznie do /tmp/fmi-stage14-ids.json (0600).
 */

use App\Models\Asset;
use App\Models\ClientAccess;
use App\Models\Field;
use App\Models\FieldRevision;
use App\Models\FieldValue;
use App\Models\FigmaFrame;
use App\Models\FigmaPreview;
use App\Models\ReviewRevision;
use App\Models\ReviewScreen;
use App\Enums\ReviewRevisionStatus;
use App\Models\Page;
use App\Models\Project;
use App\Services\AssetService;
use App\Services\ClientAccessService;
use App\Services\ContentService;
use App\Services\ExportService;
use App\Services\SectionService;
use App\Support\Actor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

$manifestPath = '/tmp/fmi-stage03-fixtures.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$eleven = json_decode((string) file_get_contents('/tmp/fmi-stage11-ids.json'), true);
$admin = get_userdata((int) $manifest['users']['admin']);
$actor = Actor::user($admin);
$service = app(SectionService::class);
$content = app(ContentService::class);
$assets = app(AssetService::class);
$accesses = app(ClientAccessService::class);

$projectA = Project::query()->findOrFail((int) $manifest['projects']['a']);
$projectB = Project::query()->findOrFail((int) $manifest['projects']['b']);
// Oba moduły: macierz i audyt UI obejmują też trasy Design Review (bez modułu → 404 dla wszystkich).
Project::query()->whereKey([$projectA->id, $projectB->id])->update(['review_enabled' => true]);
$pageB = Page::query()->findOrFail((int) $manifest['pages']['b']);

$sectionB = $service->create($pageB, ['name' => 'Obca sekcja'], $admin);
$textB = $service->createField($sectionB, ['type' => 'text', 'label' => 'Obcy tekst'], $admin);
$repeaterB = $service->createField($sectionB, ['type' => 'repeater', 'label' => 'Obcy repeater'], $admin);
$galleryB = $service->createField($sectionB, ['type' => 'gallery', 'label' => 'Obca galeria'], $admin);
$content->save($textB, 'Obca treść', 0, $actor);
$content->save($textB, 'Obca treść 2', 1, $actor);
[$rowB] = $content->addRow($repeaterB, 0, $actor);
$assetB = $assets->store(new UploadedFile(__DIR__ . '/../stage09/fixtures/ok.png', 'ok.png', null, null, true), $galleryB, $actor);
$content->attachAsset($galleryB, $assetB, 0, $actor);

$titleA = Field::query()->findOrFail($eleven['fields']['title']);
$content->save($titleA, 'Tytuł strony 2', 1, $actor);
$valueOf = fn (Field $field) => FieldValue::query()->where('field_id', $field->id)->firstOrFail();
$revisionOf = fn (FieldValue $value) => FieldRevision::query()->where('field_value_id', $value->id)->firstOrFail();
$assetA = Asset::query()->where('project_id', $projectA->id)->firstOrFail();

$exportA = app(ExportService::class)->run($projectA, $admin);
$exportB = app(ExportService::class)->run($projectB, $admin);

// Podgląd bez pobierania z Figmy: inaczej trasa dawałaby 404 także właścicielowi i odmowy nic by nie dowodziły.
$previewPath = 'figma/' . $projectA->uuid . '/e2e-1-2.png';
Storage::disk('fmi')->put($previewPath, (string) file_get_contents(__DIR__ . '/../stage09/fixtures/ok.png'));
$frame = FigmaFrame::query()->create([
	'project_id' => $projectA->id, 'file_key' => 'abcdefghij12', 'node_id' => '1:2', 'node_name' => 'Test',
	'document' => ['id' => '1:2', 'children' => []], 'fetched_at' => now(),
]);
FigmaPreview::query()->create([
	'project_id' => $projectA->id, 'frame_id' => $frame->id, 'file_key' => 'abcdefghij12', 'node_id' => '1:2',
	'node_name' => 'Test', 'position' => 0, 'format' => 'png', 'path' => $previewPath, 'mime_type' => 'image/png',
]);

// Ekrany rewizji (Design Review, etap 07) — trasa obrazu ekranu: własny ekran A z obrazem, obcy ekran B.
$screenOf = function (Project $project, ?FigmaFrame $frame) use ($admin) {
	$revision = ReviewRevision::query()->create(['project_id' => $project->id, 'number' => 1, 'title' => 'Rewizja 1', 'status' => ReviewRevisionStatus::Draft, 'figma_file_key' => 'abcdefghij12', 'created_by' => $admin->ID]);

	return ReviewScreen::query()->create(['project_id' => $project->id, 'revision_id' => $revision->id, 'figma_frame_id' => $frame?->id, 'file_key' => 'abcdefghij12', 'node_id' => '1:2', 'name' => 'Test', 'width' => 1, 'height' => 1, 'surface_confirmed' => true, 'created_by' => $admin->ID]);
};
$screenA = $screenOf($projectA, $frame);
$screenB = $screenOf($projectB, null);
// Wątki i wiadomości (etap 08) — trasy odpowiedzi, statusu i edycji.
$threadA = app(\App\Services\ReviewCommentService::class)->create($projectA, ['body' => 'Uwaga A', 'context' => 'E2E'], $admin);
$linkA = $accesses->generateReview($projectA, $admin, now()->addDays(7), 'E2E', true, false)->access;
$linkB = $accesses->generateReview($projectB, $admin, now()->addDays(7), 'E2E', true, false)->access;
// Decyzje do macierzy tras ({decision}) — wpis bezpośredni, bez wywołania API Figmy.
$decisionOf = fn ($project, $screen) => \App\Models\ReviewDecision::query()->create([
	'project_id' => $project->id, 'revision_id' => $screen->revision_id, 'decision' => ReviewRevisionStatus::ChangesRequested, 'note' => 'E2E',
	'actor_type' => 'user', 'actor_user_id' => $admin->ID, 'actor_name' => $admin->display_name, 'identification' => 'wp_account',
	'revision_number' => 1, 'version_status' => 'unverified',
]);
$decisionA = $decisionOf($projectA, $screenA);
$decisionB = $decisionOf($projectB, $screenB);
// Powiadomienia do macierzy ({notification}): A — administratora (właściciel ścieżki), B — innej osoby (IDOR).
$notificationOf = fn (int $userId, $project) => \App\Models\Notification::query()->create([
	'user_id' => $userId, 'project_id' => $project->id, 'type' => \App\Models\Notification::DECISION,
	'event_key' => 'e2e:' . $project->id . ':' . $userId, 'subject_id' => 0, 'data' => ['revision' => 1, 'decision' => 'approved', 'actor' => 'E2E'],
]);
$notificationA = $notificationOf($admin->ID, $projectA);
$notificationB = $notificationOf((int) $manifest['users']['manager'], $projectA);
$threadB = app(\App\Services\ReviewCommentService::class)->create($projectB, ['body' => 'Uwaga B', 'context' => 'E2E'], $admin);

$tokenB = $accesses->generate($projectB, $admin);
$revoked = $accesses->generate($projectA, $admin);
$accesses->revoke($revoked->access, $admin);
$spare = $accesses->generate($projectA, $admin);
$foreignAccess = ClientAccess::query()->whereKey($tokenB->access->id)->firstOrFail();

$subscriber = wp_insert_user([
	'user_login' => 'fmitest_subscriber_' . $manifest['suffix'],
	'user_email' => 'fmitest_subscriber_' . $manifest['suffix'] . '@fmi-test.invalid',
	'user_pass' => wp_generate_password(24),
	'role' => 'subscriber',
]);

if (is_wp_error($subscriber)) {
	fwrite(STDERR, "BLOCKED: nie udało się utworzyć konta bez dostępu.\n");
	exit(2);
}

$manifest['users']['subscriber'] = $subscriber;
$manifest['cookies']['subscriber'] = LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie($subscriber, time() + 3600, 'logged_in');
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$valueA = $valueOf($titleA);
$valueB = $valueOf($textB);
$path = '/tmp/fmi-stage14-ids.json';
touch($path);
chmod($path, 0600);
file_put_contents($path, json_encode([
	'tokens' => ['a' => $eleven['token'], 'b' => $tokenB->token, 'revoked' => $revoked->token, 'spare' => $spare->token],
	'a' => [
		'project' => $projectA->id, 'page' => (int) $manifest['pages']['a'], 'section' => $eleven['sections']['hero'],
		'field' => $titleA->id, 'value' => $valueA->id, 'revision' => $revisionOf($valueA)->id, 'asset' => $assetA->uuid,
		'row' => $eleven['rows']['a'], 'export' => $exportA->uuid, 'clientAccess' => $spare->access->id, 'screen' => $screenA->id, 'thread' => $threadA->id, 'link' => $linkA->id, 'decision' => $decisionA->id, 'notification' => $notificationA->id, 'comment' => $threadA->comments()->first()->id,
	],
	'b' => [
		'project' => $projectB->id, 'page' => $pageB->id, 'section' => $sectionB->id,
		'field' => $textB->id, 'value' => $valueB->id, 'revision' => $revisionOf($valueB)->id, 'asset' => $assetB->uuid,
		'row' => $rowB->uuid, 'export' => $exportB->uuid, 'clientAccess' => $foreignAccess->id, 'screen' => $screenB->id, 'thread' => $threadB->id, 'link' => $linkB->id, 'decision' => $decisionB->id, 'notification' => $notificationB->id, 'comment' => $threadB->comments()->first()->id,
	],
	'staff_user' => (int) $manifest['users']['outsider'],
]));

fwrite(STDOUT, "Dane etapu 14 zasiane.\n");
