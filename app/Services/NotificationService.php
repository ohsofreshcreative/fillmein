<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\Notification;
use App\Models\Project;
use App\Support\Staff;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use WP_User;

/**
 * Powiadomienia w panelu o zdarzeniach Design Review (uwaga, odpowiedź, decyzja).
 *
 * Odbiorcy: osoby przypisane do projektu (autor, opiekun, członkowie) z aktywnym kontem aplikacji, którym
 * ProjectPolicy@viewReview pozwala oglądać recenzję — bez autora zdarzenia. Administratorzy widzący wszystkie
 * projekty bez przypisania nie dostają powiadomień (dostęp ≠ udział w projekcie).
 * Deduplikacja: `event_key` unikalny per odbiorca (insertOrIgnore) — ponowna obsługa zdarzenia nic nie dodaje.
 * Wywołanie w tej samej transakcji co zapis zdarzenia: wycofanie zapisu wycofuje powiadomienia.
 * E-mail: odroczony (docs/design-review/11-powiadomienia.md — brak kolejki i obsługi ponowień w istniejącej wysyłce).
 */
class NotificationService
{
	public const EXCERPT = 140;

	public function __construct(private ProjectAssignmentService $assignments)
	{
	}

	/**
	 * @param array<string, scalar|null> $data
	 * @return int liczba nowych powiadomień
	 */
	public function notify(Project $project, string $type, int $subjectId, array $data, ?int $actorUserId): int
	{
		if (! $project->review_enabled) {
			return 0;
		}

		$key = $type . ':' . $subjectId;
		$now = now();
		$rows = [];

		foreach ($this->recipients($project) as $user) {
			if ($user->ID === $actorUserId) {
				continue;
			}

			$rows[] = [
				'user_id' => $user->ID,
				'project_id' => $project->id,
				'type' => $type,
				'event_key' => $key,
				'subject_id' => $subjectId,
				'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
				'created_at' => $now,
			];
		}

		return $rows === [] ? 0 : Notification::query()->insertOrIgnore($rows);
	}

	public static function excerpt(string $body): string
	{
		return Str::limit(trim(preg_replace('/\s+/u', ' ', $body) ?? ''), self::EXCERPT);
	}

	/**
	 * @return WP_User[]
	 */
	public function recipients(Project $project): array
	{
		$ids = $this->assignments->assignedUserIds($project);
		$users = [];

		foreach ($ids as $id) {
			$user = Staff::find($id);

			if ($user && ! AccountStatus::isDeactivated($user->ID) && Gate::forUser($user)->allows('viewReview', $project)) {
				$users[] = $user;
			}
		}

		return $users;
	}
}
