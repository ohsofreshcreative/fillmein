<?php

/**
 * Pomocnik scenariusza: screen <project> (ekran z obrazem testowym w bieżącej rewizji → ID), state <project>,
 * value <field>, link <project> (ID linku recenzji), notif <user> <project> (ID pierwszego), notif-count <project>,
 * renotify <project> (ponowna obsługa ostatniego zdarzenia → liczba nowych), remove-member <project> <user>,
 * access <id> (stan linku Treści).
 */

use App\Models\ClientAccess;
use App\Models\FigmaFrame;
use App\Models\FigmaPreview;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ReviewDecision;
use App\Models\ReviewScreen;
use App\Services\NotificationService;
use App\Services\ProjectAssignmentService;
use Illuminate\Support\Facades\Storage;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$admin = get_user_by('id', (int) $manifest['users']['admin']);
[$cmd, $a, $b] = array_pad($args, 3, null);

switch ($cmd) {
	case 'screen':
		$project = Project::findOrFail((int) $a);
		$revision = $project->reviewRevisions()->firstOrFail();
		$image = imagecreatetruecolor(600, 1200);
		imagefill($image, 0, 0, imagecolorallocate($image, 230, 230, 230));
		ob_start();
		imagepng($image);
		$path = 'figma/' . $project->uuid . '/acceptance.png';
		Storage::disk('fmi')->put($path, (string) ob_get_clean());
		$frame = FigmaFrame::query()->create(['project_id' => $project->id, 'file_key' => $revision->figma_file_key, 'node_id' => '1:2', 'node_name' => 'Ekran', 'width' => 600, 'height' => 1200, 'document' => ['id' => '1:2', 'children' => []], 'fetched_at' => now()]);
		FigmaPreview::query()->create(['project_id' => $project->id, 'frame_id' => $frame->id, 'file_key' => $revision->figma_file_key, 'node_id' => '1:2', 'node_name' => 'Ekran', 'position' => 0, 'format' => 'png', 'path' => $path, 'mime_type' => 'image/png', 'width' => 600, 'height' => 1200]);
		echo ReviewScreen::query()->create(['project_id' => $project->id, 'revision_id' => $revision->id, 'figma_frame_id' => $frame->id, 'file_key' => $revision->figma_file_key, 'node_id' => '1:2', 'name' => 'Ekran główny', 'width' => 600, 'height' => 1200, 'surface_confirmed' => true, 'created_by' => $admin->ID])->id . "\n";
		break;
	case 'state':
		$project = Project::findOrFail((int) $a);
		$thread = $project->reviewThreads()->orderBy('number')->first();
		echo json_encode([
			'revisions' => $project->reviewRevisions()->get()->sortBy('number')->map(fn ($r) => $r->number . ':' . $r->status->value)->values(),
			'decisions' => ReviewDecision::query()->where('project_id', $project->id)->orderBy('id')->get()->map(fn ($d) => $d->revision_number . ':' . $d->decision->value . ':' . $d->actor_type->value . ':' . $d->identification)->values(),
			'thread' => $thread ? ['id' => $thread->id, 'status' => $thread->status->value, 'screen' => $thread->screen_id, 'revision' => $thread->revision?->number, 'pin' => $thread->pin_x !== null, 'messages' => $thread->comments()->count(), 'events' => $thread->events()->count()] : null,
			'prototype' => $project->reviewRevisions()->first()?->figma_file_key,
		]) . "\n";
		break;
	case 'value':
		echo \App\Models\FieldValue::query()->where('field_id', (int) $a)->value('value') . "\n";
		break;
	case 'link':
		echo ClientAccess::query()->where('project_id', (int) $a)->review()->latest('id')->value('id') . "\n";
		break;
	case 'notif':
		echo Notification::query()->where('user_id', (int) $a)->where('project_id', (int) $b)->orderBy('id')->value('id') . "\n";
		break;
	case 'notif-count':
		echo Notification::query()->where('project_id', (int) $a)->count() . "\n";
		break;
	case 'renotify':
		$last = Notification::query()->where('project_id', (int) $a)->latest('id')->firstOrFail();
		echo app(NotificationService::class)->notify(Project::findOrFail((int) $a), $last->type, $last->subject_id, $last->data, null) . "\n";
		break;
	case 'remove-member':
		app(ProjectAssignmentService::class)->removeMember(Project::findOrFail((int) $a), (int) $b, $admin);
		echo "ok\n";
		break;
	case 'access':
		$access = ClientAccess::findOrFail((int) $a);
		echo $access->scope . '|' . ($access->revoked_at ? 'revoked' : 'active') . "\n";
		break;
}
