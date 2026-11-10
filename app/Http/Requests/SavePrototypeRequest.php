<?php

namespace App\Http\Requests;

use App\Integrations\Figma\FigmaUrl;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Odnośnik prototypu Figmy projektu w Design Review. Autoryzacja: ProjectPolicy@manageReviews.
 */
class SavePrototypeRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('manageReviews', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'figma_url' => ['bail', 'required', 'string', 'max:' . FigmaUrl::PROTOTYPE_MAX_LENGTH, function (string $attribute, mixed $value, Closure $fail) {
				if ($error = FigmaUrl::prototypeError((string) $value)) {
					$fail($error);
				}
			}],
		];
	}

	public function messages(): array
	{
		return [
			'figma_url.required' => 'Wklej link do prototypu Figmy.',
			'figma_url.max' => 'Link jest za długi (najwyżej ' . FigmaUrl::PROTOTYPE_MAX_LENGTH . ' znaków).',
		];
	}

	protected function prepareForValidation(): void
	{
		$this->merge(['figma_url' => is_string($this->figma_url) ? trim($this->figma_url) : $this->figma_url]);
	}
}
