<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Kolejność rodzeństwa podstron. Autoryzacja: ProjectPolicy@managePages.
 * Przynależność identyfikatorów do projektu i poziomu sprawdza PageService.
 */
class ReorderPagesRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('managePages', $this->route('project'));

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
			'order' => 'kolejność podstron',
			'parent_id' => 'podstrona nadrzędna',
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
