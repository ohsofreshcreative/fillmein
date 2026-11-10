<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Duplikowanie podstrony przez pracownika. Autoryzacja: ProjectPolicy@managePages.
 *
 * Domyślnie kopiujemy wyłącznie definicje (bez treści) — `with_values` musi być wybrane jawnie.
 */
class DuplicatePageRequest extends FormRequest
{
	public function authorize(): bool
	{
		// `authorize`, nie `allows`: zachowuje 404 z ProjectPolicy dla nieprzypisanych (bez ujawniania projektu).
		Gate::authorize('managePages', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'name' => ['nullable', 'string', 'max:190'],
			'parent_id' => ['nullable', 'integer', 'min:1'],
			'with_values' => ['nullable', 'boolean'],
			'with_children' => ['nullable', 'boolean'],
		];
	}

	public function attributes(): array
	{
		return [
			'name' => 'nazwa duplikatu',
			'parent_id' => 'podstrona nadrzędna',
		];
	}

	protected function prepareForValidation(): void
	{
		$this->merge([
			'name' => is_string($this->name) ? trim($this->name) : $this->name,
			'with_values' => $this->boolean('with_values'),
			'with_children' => $this->boolean('with_children'),
		]);
	}

	/**
	 * Brak `parent_id` w żądaniu = duplikat obok źródła (ten sam rodzic); puste `parent_id` = poziom główny.
	 *
	 * @return array{name: ?string, parent_id?: ?int, with_values: bool, with_children: bool}
	 */
	public function options(): array
	{
		$data = $this->validated();

		$options = [
			'name' => ($data['name'] ?? null) ?: null,
			'with_values' => (bool) ($data['with_values'] ?? false),
			'with_children' => (bool) ($data['with_children'] ?? false),
		];

		if (array_key_exists('parent_id', $data)) {
			$options['parent_id'] = $data['parent_id'] !== null ? (int) $data['parent_id'] : null;
		}

		return $options;
	}
}
