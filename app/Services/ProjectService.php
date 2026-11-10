<?php

namespace App\Services;

use App\Enums\ProjectModule;
use App\Enums\ProjectStatus;
use App\Integrations\Figma\FigmaUrl;
use App\Models\Project;
use App\Support\Actor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use WP_User;

/**
 * Cykl życia projektu. Przypisania pracowników: ProjectAssignmentService.
 */
class ProjectService
{
	public function __construct(
		private ActivityLogger $activity,
		private ProjectAssignmentService $assignments,
	) {
	}

	/**
	 * @param array{name: string, client_name?: ?string, manager_id?: ?int, member_ids?: int[], figma_url?: ?string, modules?: string[]} $data
	 */
	public function create(array $data, WP_User $author): Project
	{
		return DB::transaction(function () use ($data, $author) {
			$figmaUrl = $data['figma_url'] ?? null;

			$project = Project::create([
				'name' => $data['name'],
				'client_name' => $data['client_name'] ?? null,
				'author_id' => $author->ID,
				'manager_id' => $data['manager_id'] ?? $author->ID,
				'figma_file_url' => $figmaUrl,
				'figma_file_key' => $figmaUrl ? FigmaUrl::fileKey($figmaUrl) : null,
			] + self::moduleColumns($data['modules'] ?? [ProjectModule::Content->value]));

			$this->activity->log('project.created', Actor::user($author), $project, $project);

			if (! empty($data['member_ids'])) {
				$this->assignments->syncMembers($project, $data['member_ids'], $author);
			}

			return $project;
		});
	}

	/**
	 * Ustawienia projektu. Opiekuna i listę pracowników zmienia ProjectAssignmentService,
	 * żeby reguły przypisań (tylko aktywni pracownicy, dziennik) były w jednym miejscu.
	 *
	 * @param array{name?: string, client_name?: ?string, status?: ?string, manager_id?: ?int, figma_url?: ?string, modules?: string[]} $data
	 */
	public function update(Project $project, array $data, WP_User $by): Project
	{
		return DB::transaction(function () use ($project, $data, $by) {
			$attributes = [];
			$changed = [];

			if (array_key_exists('name', $data) && $data['name'] !== $project->name) {
				$attributes['name'] = $data['name'];
				$changed[] = 'name';
			}

			if (array_key_exists('client_name', $data) && ($data['client_name'] ?: null) !== $project->client_name) {
				$attributes['client_name'] = $data['client_name'] ?: null;
				$changed[] = 'client_name';
			}

			if (array_key_exists('figma_url', $data)) {
				$figmaUrl = $data['figma_url'] ?: null;

				if ($figmaUrl !== $project->figma_file_url) {
					$attributes['figma_file_url'] = $figmaUrl;
					$attributes['figma_file_key'] = $figmaUrl ? FigmaUrl::fileKey($figmaUrl) : null;
					$changed[] = 'figma_file_url';
				}
			}

			if (array_key_exists('modules', $data)) {
				foreach (self::moduleColumns($data['modules']) as $column => $enabled) {
					if ((bool) $project->getAttribute($column) !== $enabled) {
						$attributes[$column] = $enabled;
						$changed[] = $column;
					}
				}
			}

			if ($attributes !== []) {
				$project->update($attributes);
			}

			if (array_key_exists('manager_id', $data)) {
				$managerId = $data['manager_id'] ? (int) $data['manager_id'] : null;

				if ($managerId !== $project->manager_id) {
					$this->assignments->setManager($project, $managerId, $by);
					$project->refresh();
				}
			}

			if (! empty($data['status'])) {
				$status = ProjectStatus::tryFrom((string) $data['status']);

				if ($status && $status !== $project->status) {
					$this->changeStatus($project, $status, $by);
				}
			}

			if ($changed !== []) {
				$this->activity->log('project.updated', Actor::user($by), $project, $project, [
					'changed' => $changed,
				]);
			}

			return $project->refresh();
		});
	}

	/**
	 * Wybrane moduły → kolumny `*_enabled`. Wyłączenie modułu niczego nie usuwa: treści i rewizje zostają w bazie.
	 *
	 * @param string[] $modules
	 * @return array<string, bool>
	 */
	public static function moduleColumns(array $modules): array
	{
		$columns = [];

		foreach (ProjectModule::cases() as $module) {
			$columns[$module->column()] = in_array($module->value, $modules, true);
		}

		return $columns;
	}

	public function changeStatus(Project $project, ProjectStatus $status, WP_User $by): Project
	{
		$previous = $project->status;

		if ($previous === $status) {
			return $project;
		}

		$project->update(['status' => $status]);

		$this->activity->log('project.status_changed', Actor::user($by), $project, $project, [
			'from' => $previous->value,
			'to' => $status->value,
		]);

		return $project;
	}

	/**
	 * Przeniesienie do archiwum to zmiana statusu — projekt i jego treść zostają dostępne.
	 */
	public function archive(Project $project, WP_User $by): Project
	{
		return $this->changeStatus($project, ProjectStatus::Archived, $by);
	}

	public function unarchive(Project $project, WP_User $by): Project
	{
		return $this->changeStatus($project, ProjectStatus::Collecting, $by);
	}

	/**
	 * Usunięcie jest miękkie (soft delete): dane zostają w bazie, a aktywne linki klientów
	 * przestają działać (ClientAccessService::resolve wymaga istniejącego projektu).
	 */
	/**
	 * Wszystkie albo żaden. Zapis jak przy pojedynczym usunięciu: miękko, z wpisem w dzienniku.
	 *
	 * @param Collection<int, Project> $projects
	 */
	public function deleteMany(Collection $projects, WP_User $by): int
	{
		DB::transaction(fn () => $projects->each(fn (Project $project) => $this->delete($project, $by)));

		return $projects->count();
	}

	public function delete(Project $project, WP_User $by): void
	{
		DB::transaction(function () use ($project, $by) {
			$this->activity->log('project.deleted', Actor::user($by), $project, $project, [
				'project_name' => $project->name,
			]);

			$project->delete();
		});
	}
}
