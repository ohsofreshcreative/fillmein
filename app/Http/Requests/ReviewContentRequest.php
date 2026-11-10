<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Oznaczenie treści do poprawy i zdjęcie oznaczenia. Autoryzacja: ProjectPolicy@editContent.
 */
class ReviewContentRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('editContent', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return $this->routeIs('*.clear') ? [] : ['note' => ['required', 'string', 'max:1000']];
	}

	public function attributes(): array
	{
		return ['note' => 'komentarz'];
	}
}
