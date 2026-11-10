<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Project;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Model;
use WP_User;

/**
 * Zapis zdarzeń do fmi_activity_log.
 *
 * Nazwy zdarzeń w formacie `obszar.czynność`, np. `project.created`, `project.manager_changed`,
 * `user.created`, `user.role_changed`, `client_access.generated`, `content.updated`.
 * Wykonawcę wskazuje Actor (pracownik / klient / system). Podmiotem może być model Eloquent
 * albo użytkownik WordPressa (`subject_type = user`).
 * W `properties` nie wolno umieszczać haseł, tokenów ani innych sekretów.
 */
class ActivityLogger
{
	public const SUBJECT_USER = 'user';

	public function log(string $event, Actor $actor, ?Project $project = null, Model|WP_User|null $subject = null, array $properties = []): Activity
	{
		if ($project) {
			$properties['project_name'] ??= $project->name;
		}

		[$subjectType, $subjectId] = match (true) {
			$subject instanceof WP_User => [self::SUBJECT_USER, $subject->ID],
			$subject instanceof Model => [$subject->getMorphClass(), $subject->getKey()],
			default => [null, null],
		};

		return Activity::create([
			'project_id' => $project?->id,
			'actor_type' => $actor->type,
			'user_id' => $actor->userId,
			'client_access_id' => $actor->clientAccessId,
			'event' => $event,
			'subject_type' => $subjectType,
			'subject_id' => $subjectId,
			'properties' => $properties ?: null,
		]);
	}
}
