<?php

/**
 * Pomocnik testu: revoke <access>, state <project> (stan rewizji i decyzji jako JSON), clear-limits,
 * new-revision <project> (jawna nowa rewizja jako opiekun).
 */

use App\Models\ClientAccess;
use App\Models\Project;
use App\Models\ReviewDecision;
use App\Services\ClientAccessService;
use App\Services\ReviewService;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$manager = get_user_by('id', (int) $manifest['users']['manager']);
[$cmd, $arg] = [$args[0] ?? '', $args[1] ?? ''];

switch ($cmd) {
	case 'revoke':
		app(ClientAccessService::class)->revoke(ClientAccess::findOrFail((int) $arg), $manager);
		echo "ok\n";
		break;
	case 'new-revision':
		echo app(ReviewService::class)->createRevision(Project::findOrFail((int) $arg), $manager)->number . "\n";
		break;
	case 'clear-limits':
		// Tylko limit wejść przez link (adres lokalny) — bez czyszczenia innych danych cache.
		foreach (['127.0.0.1', '::1'] as $ip) {
			Illuminate\Support\Facades\RateLimiter::clear(md5('fmi-review-link' . 'review-link|' . $ip));
		}
		echo "ok\n";
		break;
	case 'state':
		$project = Project::findOrFail((int) $arg);
		echo json_encode([
			'revisions' => $project->reviewRevisions()->get()->map(fn ($r) => $r->number . ':' . $r->status->value)->sortBy(fn ($v) => (int) $v)->values(),
			'decisions' => ReviewDecision::query()->where('project_id', $project->id)->orderBy('id')->get()->map(fn ($d) => [
				'rev' => $d->revision_number, 'decision' => $d->decision->value, 'actor' => $d->actor_type->value,
				'user' => $d->actor_user_id, 'access' => $d->actor_access_id, 'name' => $d->actor_name,
				'ident' => $d->identification, 'version' => $d->version_status, 'note' => $d->note,
			]),
		]) . "\n";
		break;
}
