<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Zapis klienta. Autoryzację daje ważny token (middleware `fmi.client`); przynależność pola i wiersza
 * do projektu oraz reguły typu pola sprawdza ClientEditorService.
 */
class ClientContentRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'base_version' => ['required', 'integer', 'min:0'],
			'row' => ['nullable', 'uuid'],
			'value' => ['nullable', 'string', 'max:100000'],
			'label' => ['nullable', 'string', 'max:500'],
			'url' => ['nullable', 'string', 'max:2000'],
			'file' => ['sometimes', 'file'],
			'alt' => ['nullable', 'string', 'max:500'],
			'order' => ['sometimes', 'required', 'array', 'max:500'],
			'order.*' => ['uuid', 'distinct'],
		];
	}

	public function attributes(): array
	{
		return ['value' => 'treść', 'label' => 'tekst linku', 'url' => 'adres linku', 'order' => 'kolejność', 'file' => 'plik', 'alt' => 'tekst alternatywny'];
	}

	public function baseVersion(): int
	{
		return (int) $this->validated()['base_version'];
	}

	public function rowUuid(): ?string
	{
		return $this->validated()['row'] ?? null;
	}
}
