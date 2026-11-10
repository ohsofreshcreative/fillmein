<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Pobranie frame'u podstrony z Figmy. Autoryzacja: ProjectPolicy@managePages.
 * `Gate::authorize` zachowuje 404 dla pracownika bez przypisania (zamiast ogólnego 403).
 */
class FetchFigmaFrameRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('managePages', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'force' => ['nullable', 'boolean'],
		];
	}

	protected function prepareForValidation(): void
	{
		$this->merge(['force' => $this->boolean('force')]);
	}

	public function force(): bool
	{
		return (bool) ($this->validated()['force'] ?? false);
	}
}
