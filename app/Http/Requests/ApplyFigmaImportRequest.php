<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Zatwierdzenie podglądu importu sekcji i pól z Figmy. Autoryzacja: ProjectPolicy@configureFields
 * (404 dla pracownika bez przypisania). `fingerprint` to odcisk podglądu, który widział pracownik.
 */
class ApplyFigmaImportRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('configureFields', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
			'selection' => ['sometimes', 'boolean'],
			'selected' => ['sometimes', 'array'],
			'selected.*' => ['string', 'max:300'],
		];
	}

	public function messages(): array
	{
		return [
			'fingerprint.*' => 'Podgląd importu jest nieprawidłowy. Odśwież stronę i spróbuj ponownie.',
		];
	}

	/**
	 * Wybrane zmiany. Null: żądanie bez wyboru, czyli zmiany domyślne. `selection` odróżnia to od pustego wyboru.
	 *
	 * @return ?string[]
	 */
	public function selection(): ?array
	{
		return $this->boolean('selection') ? array_values((array) ($this->validated()['selected'] ?? [])) : null;
	}

	public function fingerprint(): string
	{
		return (string) $this->validated()['fingerprint'];
	}
}
