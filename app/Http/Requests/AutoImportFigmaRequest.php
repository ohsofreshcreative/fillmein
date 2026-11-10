<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Masowa „Analiza i import pól” jednej podstrony z kolejki na liście podstron. Autoryzacja jak przy ręcznym
 * imporcie: ProjectPolicy@configureFields (404 bez przypisania).
 */
class AutoImportFigmaRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('configureFields', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [];
	}
}
