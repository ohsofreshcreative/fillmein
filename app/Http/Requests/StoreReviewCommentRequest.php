<?php

namespace App\Http\Requests;

use App\Models\ReviewComment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Nowa uwaga w Design Review. Autoryzacja: ProjectPolicy@viewReview (przypisanie do projektu).
 * Współrzędne: oba albo żadna, liczby 0–1; pozostałe reguły kontekstu — ReviewCommentService.
 */
class StoreReviewCommentRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('viewReview', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'body' => ['required', 'string', 'max:' . ReviewComment::BODY_MAX],
			'screen_id' => ['nullable', 'integer', 'min:1'],
			'context' => ['nullable', 'string', 'max:190'],
			'x' => ['nullable', 'required_with:y', 'numeric', 'between:0,1'],
			'y' => ['nullable', 'required_with:x', 'numeric', 'between:0,1'],
			'request_id' => ['nullable', 'uuid'],
		];
	}

	public function messages(): array
	{
		return [
			'body.required' => 'Wpisz treść uwagi.',
			'body.max' => 'Uwaga może mieć najwyżej ' . ReviewComment::BODY_MAX . ' znaków.',
			'context.max' => 'Opis kontekstu może mieć najwyżej 190 znaków.',
			'x.between' => 'Pozycja pinezki jest poza ekranem.',
			'y.between' => 'Pozycja pinezki jest poza ekranem.',
			'x.required_with' => 'Pozycja pinezki jest niepełna.',
			'y.required_with' => 'Pozycja pinezki jest niepełna.',
			'x.numeric' => 'Pozycja pinezki musi być liczbą.',
			'y.numeric' => 'Pozycja pinezki musi być liczbą.',
		];
	}

	protected function prepareForValidation(): void
	{
		$this->merge(['body' => is_string($this->body) ? trim($this->body) : $this->body]);
	}
}
