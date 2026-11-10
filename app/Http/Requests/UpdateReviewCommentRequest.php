<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesReviewThread;
use App\Models\ReviewComment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Edycja własnej wiadomości. Autoryzacja: ProjectPolicy@editReviewComment. `version` = lock_version z chwili
 * wczytania formularza (jednoczesna edycja → konflikt, bez nadpisania).
 */
class UpdateReviewCommentRequest extends FormRequest
{
	use ResolvesReviewThread;

	public function authorize(): bool
	{
		Gate::authorize('viewReview', $this->project());
		Gate::authorize('editReviewComment', [$this->project(), $this->comment()]);

		return true;
	}

	public function rules(): array
	{
		return [
			'body' => ['required', 'string', 'max:' . ReviewComment::BODY_MAX],
			'version' => ['required', 'integer', 'min:0'],
		];
	}

	public function messages(): array
	{
		return [
			'body.required' => 'Treść wiadomości nie może być pusta.',
			'body.max' => 'Wiadomość może mieć najwyżej ' . ReviewComment::BODY_MAX . ' znaków.',
			'version.required' => 'Formularz jest nieaktualny — odśwież stronę.',
		];
	}

	protected function prepareForValidation(): void
	{
		$this->errorBag = 'comment' . (int) $this->route('comment');
		$this->merge(['body' => is_string($this->body) ? trim($this->body) : $this->body]);
	}
}
