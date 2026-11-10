<?php

namespace App\Http\Requests;

use App\Enums\FieldType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Dodanie lub edycja pola, grupy albo repeatera. Autoryzacja: ProjectPolicy@configureFields.
 * Unikalność klucza, limit zagnieżdżenia, pole nadrzędne i zmianę typu sprawdza SectionService.
 */
class SaveFieldRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('configureFields', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		$maxItems = (int) config('fillmein.fields.max_items', 100);

		return [
			'label' => ['required', 'string', 'max:190'],
			'name' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/'],
			'type' => ['required', 'string', Rule::in(array_column(FieldType::cases(), 'value'))],
			'parent_id' => ['nullable', 'integer', 'min:1'],
			'is_required' => ['nullable', 'boolean'],
			'is_hidden' => ['nullable', 'boolean'],
			'instructions' => ['nullable', 'string', 'max:2000'],
			'confirm_type_change' => ['nullable', 'boolean'],
			'settings' => ['nullable', 'array'],
			'settings.max_length' => ['nullable', 'integer', 'min:1', 'max:100000'],
			'settings.placeholder' => ['nullable', 'string', 'max:190'],
			'settings.min_width' => ['nullable', 'integer', 'min:1', 'max:20000'],
			'settings.min_height' => ['nullable', 'integer', 'min:1', 'max:20000'],
			'settings.require_alt' => ['nullable', 'boolean'],
			'settings.min_items' => ['nullable', 'integer', 'min:0', 'max:' . $maxItems],
			'settings.max_items' => ['nullable', 'integer', 'min:1', 'max:' . $maxItems],
		];
	}

	public function attributes(): array
	{
		return [
			'label' => 'etykieta',
			'name' => 'klucz',
			'type' => 'typ pola',
			'parent_id' => 'pole nadrzędne',
			'instructions' => 'podpowiedź',
			'settings.max_length' => 'limit znaków',
			'settings.placeholder' => 'tekst zastępczy',
			'settings.min_width' => 'minimalna szerokość',
			'settings.min_height' => 'minimalna wysokość',
			'settings.min_items' => 'minimalna liczba elementów',
			'settings.max_items' => 'maksymalna liczba elementów',
		];
	}

	public function messages(): array
	{
		return [
			'type.in' => 'Nieobsługiwany typ pola.',
			'name.regex' => 'Klucz może zawierać tylko małe litery, cyfry i pojedyncze podkreślenia.',
		];
	}

	protected function prepareForValidation(): void
	{
		$this->merge([
			'label' => is_string($this->label) ? trim($this->label) : $this->label,
			'name' => is_string($this->name) ? mb_strtolower(trim($this->name)) : $this->name,
			'instructions' => is_string($this->instructions) ? trim($this->instructions) : $this->instructions,
			'is_required' => $this->boolean('is_required'),
			'is_hidden' => $this->boolean('is_hidden'),
			'confirm_type_change' => $this->boolean('confirm_type_change'),
		]);
	}

	public function fieldData(): array
	{
		$data = $this->validated();

		return [
			'label' => $data['label'],
			'name' => $data['name'] ?? null,
			'type' => $data['type'],
			'parent_id' => isset($data['parent_id']) ? (int) $data['parent_id'] : null,
			'is_required' => (bool) ($data['is_required'] ?? false),
			'is_hidden' => (bool) ($data['is_hidden'] ?? false),
			'instructions' => ($data['instructions'] ?? '') !== '' ? $data['instructions'] : null,
			'settings' => (array) ($data['settings'] ?? []),
			'confirm_type_change' => (bool) ($data['confirm_type_change'] ?? false),
		];
	}
}
