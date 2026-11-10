<?php

namespace App\Services;

use App\Exceptions\UserManagementException;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Support\Actor;
use App\Support\Staff;
use Illuminate\Support\Facades\DB;
use WP_User;

/**
 * Przypisania pracowników do projektów: autor, główny opiekun, dodatkowi pracownicy (fmi_project_members).
 *
 * Serwis pilnuje spójności danych (tylko aktywni pracownicy, bez duplikatów, dziennik aktywności).
 * Autoryzację („czy wykonujący może zmieniać przypisania”) sprawdza wywołujący przez ProjectPolicy@manageMembers.
 * Dezaktywacja konta nie usuwa przypisań — nieaktywny pracownik traci dostęp przez brak capabilities.
 */
class ProjectAssignmentService
{
	public function __construct(private ActivityLogger $activity)
	{
	}

	public function setManager(Project $project, ?int $managerId, WP_User $by): void
	{
		if ($managerId !== null) {
			$this->assertAssignable($managerId);
		}

		$previous = $project->manager_id;

		if ($previous === $managerId) {
			return;
		}

		DB::transaction(function () use ($project, $managerId) {
			$project->update(['manager_id' => $managerId]);

			// Opiekun nie jest jednocześnie „dodatkowym” pracownikiem.
			if ($managerId !== null) {
				$project->members()->where('user_id', $managerId)->delete();
			}
		});

		$this->activity->log('project.manager_changed', Actor::user($by), $project, $project, [
			'from' => $previous,
			'to' => $managerId,
		]);
	}

	public function addMember(Project $project, int $userId, WP_User $by): void
	{
		$this->assertAssignable($userId);

		if ($this->isCore($project, $userId) || $project->members()->where('user_id', $userId)->exists()) {
			return;
		}

		$project->members()->create(['user_id' => $userId, 'added_by' => $by->ID]);

		$this->activity->log('project.member_added', Actor::user($by), $project, $project, ['user_id' => $userId]);
	}

	public function removeMember(Project $project, int $userId, WP_User $by): void
	{
		if ($project->members()->where('user_id', $userId)->delete()) {
			$this->activity->log('project.member_removed', Actor::user($by), $project, $project, ['user_id' => $userId]);
		}
	}

	/**
	 * Ustawia pełną listę dodatkowych pracowników.
	 *
	 * @param int[] $userIds
	 */
	public function syncMembers(Project $project, array $userIds, WP_User $by): void
	{
		$target = collect($userIds)
			->map(fn ($id) => (int) $id)
			->reject(fn (int $id) => $this->isCore($project, $id))
			->unique()
			->values();

		$target->each(fn (int $id) => $this->assertAssignable($id));

		$current = $project->members()->pluck('user_id')->map(fn ($id) => (int) $id);

		DB::transaction(function () use ($project, $target, $current, $by) {
			$project->members()->whereNotIn('user_id', $target)->delete();

			$target->diff($current)->each(fn (int $id) => $project->members()->create([
				'user_id' => $id,
				'added_by' => $by->ID,
			]));
		});

		if ($current->sort()->values()->all() !== $target->sort()->values()->all()) {
			$this->activity->log('project.members_changed', Actor::user($by), $project, $project, [
				'members' => $target->all(),
			]);
		}
	}

	/**
	 * Pracownicy projektu: autor, opiekun i dodatkowi (unikalne ID).
	 *
	 * @return int[]
	 */
	public function assignedUserIds(Project $project): array
	{
		return collect([$project->author_id, $project->manager_id])
			->merge($project->members()->pluck('user_id'))
			->filter()
			->map(fn ($id) => (int) $id)
			->unique()
			->values()
			->all();
	}

	/**
	 * Sprzątanie po fizycznym usunięciu użytkownika w WordPressie (hook `deleted_user`).
	 */
	public function detachUser(int $userId, ?int $reassignTo = null): void
	{
		DB::transaction(function () use ($userId, $reassignTo) {
			ProjectMember::query()->where('user_id', $userId)->delete();

			Project::withTrashed()->where('author_id', $userId)->update(['author_id' => $reassignTo]);
			Project::withTrashed()->where('manager_id', $userId)->update(['manager_id' => $reassignTo]);
		});
	}

	private function isCore(Project $project, int $userId): bool
	{
		return in_array($userId, [$project->author_id, $project->manager_id], true);
	}

	private function assertAssignable(int $userId): void
	{
		if (! Staff::isStaff($userId)) {
			throw new UserManagementException('Do projektu można przypisać tylko aktywnego pracownika Fill Me In.');
		}
	}
}
