<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesReviewThread;
use App\Models\ReviewComment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Odpowiedź w wątku. Autoryzacja: ProjectPolicy@viewReview (każda osoba przypisana do projektu).
 * `request_id` (UUID) wymagany: ponowienie żądania nie tworzy drugiego wpisu.
 */
class StoreReviewReplyRequest extends FormRequest
{
	use ResolvesReviewThread;

	public function authorize(): bool
	{
		Gate::authorize('viewReview', $this->project());
		$this->thread();

		return true;
	}

	public function rules(): array
	{
		return [
			'body' => ['required', 'string', 'max:' . ReviewComment::BODY_MAX],
			'request_id' => ['required', 'uuid'],
		];
	}

	public function messages(): array
	{
		return [
			'body.required' => 'Wpisz treść odpowiedzi.',
			'body.max' => 'Odpowiedź może mieć najwyżej ' . ReviewComment::BODY_MAX . ' znaków.',
			'request_id.required' => 'Formularz jest nieaktualny — odśwież stronę.',
			'request_id.uuid' => 'Formularz jest nieaktualny — odśwież stronę.',
		];
	}

	protected function prepareForValidation(): void
	{
		$this->errorBag = 'thread' . (int) $this->route('thread');
		$this->merge(['body' => is_string($this->body) ? trim($this->body) : $this->body]);
	}
}
