<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Ręczne zmiany dopasowania podstrony do schematu strony. Autoryzacja: ProjectPolicy@export.
 * `original_*` to stan z formularza — ręczne staje się tylko to, co zmieniono. Istnienie bloków i pól
 * oraz przynależność do podstrony sprawdza SiteMappingService::saveManual().
 */
class SaveSiteMappingRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('export', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		$target = ['nullable', 'string', 'max:200'];

		return [
			'complete' => ['required', 'accepted'],
			'page' => ['sometimes', ...$target],
			'original_page' => ['sometimes', ...$target],
			'sections' => ['sometimes', 'array'],
			'sections.*' => $target,
			'original_sections' => ['sometimes', 'array'],
			'original_sections.*' => $target,
			'fields' => ['sometimes', 'array'],
			'fields.*' => $target,
			'original_fields' => ['sometimes', 'array'],
			'original_fields.*' => $target,
		];
	}

	public function messages(): array
	{
		return [
			'complete.*' => 'Formularz jest niekompletny. Odśwież stronę i spróbuj ponownie.',
		];
	}
}
