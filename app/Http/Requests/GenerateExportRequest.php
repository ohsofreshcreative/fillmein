<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Wygenerowanie eksportu: cały projekt albo wybrane podstrony. Autoryzacja: ProjectPolicy@export (404 bez przypisania).
 * Podstrony sprawdzane w zakresie projektu i bez archiwum, więc UUID z obcego projektu daje błąd walidacji.
 */
class GenerateExportRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('export', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		/** @var Project $project */
		$project = $this->route('project');

		return [
			'scope' => ['nullable', Rule::in(['project', 'pages'])],
			'pages' => ['exclude_unless:scope,pages', 'required', 'array', 'max:500'],
			'pages.*' => [
				'string',
				'uuid',
				Rule::exists('fmi_pages', 'uuid')->where('project_id', $project->id)->whereNull('archived_at'),
			],
		];
	}

	public function messages(): array
	{
		return [
			'pages.required' => 'Zaznacz co najmniej jedną podstronę.',
			'pages.*.exists' => 'Wybrana podstrona nie istnieje w tym projekcie.',
			'pages.*.uuid' => 'Wybrana podstrona nie istnieje w tym projekcie.',
		];
	}

	/**
	 * @return string[]|null `null` — cały projekt
	 */
	public function pageUuids(): ?array
	{
		return $this->input('scope') === 'pages' ? array_values(array_unique($this->validated('pages'))) : null;
	}
}
