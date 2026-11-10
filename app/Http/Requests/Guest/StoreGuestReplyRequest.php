<?php

namespace App\Http\Requests\Guest;

use App\Models\ReviewComment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Odpowiedź gościa w wątku jego projektu (wątek rozwiązywany w zakresie projektu linku — obcy → 404).
 */
class StoreGuestReplyRequest extends FormRequest
{
	public function authorize(): bool
	{
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
