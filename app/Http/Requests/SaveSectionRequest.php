<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Dodanie lub edycja sekcji podstrony. Autoryzacja: ProjectPolicy@configureFields.
 */
class SaveSectionRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('configureFields', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'name' => ['required', 'string', 'max:190'],
			'is_hidden' => ['nullable', 'boolean'],
		];
	}

	public function attributes(): array
	{
		return [
			'name' => 'nazwa sekcji',
			'is_hidden' => 'ukrycie sekcji',
		];
	}

	protected function prepareForValidation(): void
	{
		$this->merge([
			'name' => is_string($this->name) ? trim($this->name) : $this->name,
			'is_hidden' => $this->boolean('is_hidden'),
		]);
	}

	/**
	 * @return array{name: string, is_hidden: bool}
	 */
	public function sectionData(): array
	{
		$data = $this->validated();

		return ['name' => $data['name'], 'is_hidden' => (bool) ($data['is_hidden'] ?? false)];
	}
}
