<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Mapowanie podstrony na WordPress i ACF. Autoryzacja: ProjectPolicy@export (404 bez przypisania).
 * `complete` stoi na końcu formularza: POST obcięty przez max_input_vars jest odrzucany zamiast zapisany częściowo.
 */
class SaveMappingRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('export', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'complete' => ['required', 'accepted'],
			'page' => ['required', 'array'],
			'page.post_type' => ['required', 'string', 'regex:/^[a-z0-9_-]{1,20}$/'],
			'page.slug' => ['nullable', 'string', 'regex:/^[a-z0-9-]{1,190}$/'],
			'page.template' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9_\/.-]+$/', 'not_regex:/\.\./'],
			'sections' => ['sometimes', 'array'],
			'sections.*.block' => ['nullable', 'string', 'regex:/^acf\/[a-z0-9-]{1,180}$/'],
			'fields' => ['sometimes', 'array'],
			'fields.*.name' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]{0,59}$/'],
			'fields.*.type' => ['nullable', 'string', 'max:32'],
		];
	}

	public function messages(): array
	{
		return [
			'complete.*' => 'Formularz jest niekompletny. Odśwież stronę i spróbuj ponownie.',
			'page.post_type.*' => 'Typ wpisu: małe litery, cyfry, myślnik lub podkreślenie, do 20 znaków.',
			'page.slug.*' => 'Slug: małe litery, cyfry i myślniki.',
			'page.template.*' => 'Szablon: ścieżka względna bez „..”.',
			'sections.*.block.*' => 'Blok ma format acf/nazwa-bloku.',
			'fields.*.name.*' => 'Nazwa ACF: małe litery, cyfry i podkreślenia, zaczyna się od litery.',
		];
	}
}
