<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Podstrony recenzji: lista „nazwa → link do frame'u Figmy” (`screens[i][name]`, `screens[i][url]`).
 * Pojedyncze `screen_url` zostaje dla zgodności ze starszym formularzem. Autoryzacja: ProjectPolicy@manageReviews.
 */
class StoreReviewScreenRequest extends FormRequest
{
	public const MAX_ROWS = 20;

	public function authorize(): bool
	{
		Gate::authorize('manageReviews', $this->route('project'));

		return true;
	}

	protected function prepareForValidation(): void
	{
		$rows = $this->input('screens');

		if (! is_array($rows)) {
			$rows = $this->filled('screen_url') || ! $this->has('screens') ? [['name' => null, 'url' => $this->input('screen_url')]] : [];
		}

		// Puste wiersze formularza (bez nazwy i linku) pomijamy, żeby „+ Dodaj podstronę” nie blokowało zapisu.
		$rows = array_values(array_filter(
			array_map(fn ($row) => is_array($row) ? ['name' => trim((string) ($row['name'] ?? '')), 'url' => trim((string) ($row['url'] ?? ''))] : null, $rows),
			fn ($row) => $row !== null && ($row['name'] !== '' || $row['url'] !== ''),
		));

		$this->merge(['screens' => $rows]);
	}

	public function rules(): array
	{
		return [
			'screens' => ['required', 'array', 'min:1', 'max:' . self::MAX_ROWS],
			'screens.*.name' => ['nullable', 'string', 'max:190'],
			'screens.*.url' => ['required', 'string', 'max:500'],
		];
	}

	public function messages(): array
	{
		return [
			'screens.required' => 'Dodaj co najmniej jedną podstronę z linkiem do frame\'u z Figmy.',
			'screens.min' => 'Dodaj co najmniej jedną podstronę z linkiem do frame\'u z Figmy.',
			'screens.max' => 'Naraz możesz dodać najwyżej ' . self::MAX_ROWS . ' podstron.',
			'screens.*.name.max' => 'Nazwa jest za długa (najwyżej 190 znaków).',
			'screens.*.url.required' => 'Wklej link do frame\'u z Figmy.',
			'screens.*.url.max' => 'Link jest za długi.',
		];
	}

	/**
	 * @return list<array{name: ?string, url: string}>
	 */
	public function rows(): array
	{
		return array_map(fn (array $row) => ['name' => $row['name'] !== '' ? $row['name'] : null, 'url' => $row['url']], $this->validated('screens'));
	}
}
