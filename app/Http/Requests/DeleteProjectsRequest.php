<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Usuwanie zaznaczonych projektów. Każdy przechodzi przez ProjectPolicy@delete, zanim cokolwiek zostanie usunięte,
 * a projekt niewidoczny dla tej osoby daje 404, tak jak przy pojedynczym adresie.
 */
class DeleteProjectsRequest extends FormRequest
{
	private Collection $selected;

	public function rules(): array
	{
		return [
			'ids' => ['required', 'array', 'max:' . (int) config('fillmein.projects.per_page', 20)],
			'ids.*' => ['integer', 'distinct'],
		];
	}

	public function attributes(): array
	{
		return ['ids' => 'projekty'];
	}

	protected function passedValidation(): void
	{
		$ids = array_map('intval', $this->validated('ids'));
		$projects = Project::query()->whereKey($ids)->get();

		abort_if($projects->count() !== count($ids), 404);

		$projects->each(fn (Project $project) => Gate::authorize('delete', $project));

		$this->selected = $projects;
	}

	/**
	 * @return Collection<int, Project>
	 */
	public function projects(): Collection
	{
		return $this->selected;
	}
}
