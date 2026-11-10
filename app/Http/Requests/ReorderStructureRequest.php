<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Kolejność sekcji albo pól. Autoryzacja: ProjectPolicy@configureFields.
 * Przynależność identyfikatorów do podstrony i poziomu sprawdza SectionService.
 */
class ReorderStructureRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('configureFields', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'parent_id' => ['nullable', 'integer', 'min:1'],
			'order' => ['required', 'array', 'min:1', 'max:500'],
			'order.*' => ['integer', 'distinct', 'min:1'],
		];
	}

	public function attributes(): array
	{
		return [
			'order' => 'kolejność',
			'parent_id' => 'pole nadrzędne',
		];
	}

	public function parentId(): ?int
	{
		$value = $this->validated()['parent_id'] ?? null;

		return $value ? (int) $value : null;
	}

	/**
	 * @return int[]
	 */
	public function order(): array
	{
		return array_map('intval', $this->validated()['order']);
	}
}
