<?php

/**
 * Pomocnik testu: id <user> <type> [number] (ID powiadomienia), read <id> (read_at albo -),
 * unread <user> <project> (liczba), remove-member <project> <user>.
 */

use App\Models\Notification;
use App\Models\Project;
use App\Services\ProjectAssignmentService;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$admin = get_user_by('id', (int) $manifest['users']['admin']);
[$cmd, $a, $b, $c] = array_pad($args, 4, null);

switch ($cmd) {
	case 'id':
		$rows = Notification::query()->where('user_id', (int) $a)->where('type', $b)->whereIn('project_id', array_values($manifest['projects']))->orderBy('id')->get();
		echo ($c ? $rows->first(fn ($row) => (int) ($row->data['number'] ?? 0) === (int) $c) : $rows->first())?->id ?? '-';
		echo "\n";
		break;
	case 'read':
		echo (Notification::find((int) $a)?->read_at ? 'read' : 'unread') . "\n";
		break;
	case 'unread':
		echo Notification::query()->where('user_id', (int) $a)->where('project_id', (int) $b)->whereNull('read_at')->count() . "\n";
		break;
	case 'remove-member':
		app(ProjectAssignmentService::class)->removeMember(Project::findOrFail((int) $a), (int) $b, $admin);
		echo "ok\n";
		break;
}
