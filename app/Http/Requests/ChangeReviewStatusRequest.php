<?php

namespace App\Http\Requests;

use App\Enums\ReviewThreadStatus;
use App\Http\Requests\Concerns\ResolvesReviewThread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Zmiana statusu uwagi. Autoryzacja: ProjectPolicy@changeReviewStatus (opiekun; autor — tylko ponowne otwarcie).
 * `version` = lock_version wątku z chwili wczytania.
 */
class ChangeReviewStatusRequest extends FormRequest
{
	use ResolvesReviewThread;

	public function authorize(): bool
	{
		Gate::authorize('viewReview', $this->project());
		$to = ReviewThreadStatus::tryFrom((string) $this->input('status'));

		if ($to) {
			Gate::authorize('changeReviewStatus', [$this->project(), $this->thread(), $to]);
		} else {
			$this->thread();
		}

		return true;
	}

	public function rules(): array
	{
		return [
			'status' => ['required', Rule::enum(ReviewThreadStatus::class)],
			'version' => ['required', 'integer', 'min:0'],
		];
	}

	public function messages(): array
	{
		return [
			'status.required' => 'Wybierz status.',
			'status.enum' => 'Nieznany status uwagi.',
			'version.required' => 'Formularz jest nieaktualny — odśwież stronę.',
		];
	}

	public function target(): ReviewThreadStatus
	{
		return ReviewThreadStatus::from($this->validated('status'));
	}

	protected function prepareForValidation(): void
	{
		$this->errorBag = 'thread' . (int) $this->route('thread');
	}
}
