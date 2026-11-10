<?php

namespace App\Http\Requests;

use App\Enums\ReviewRevisionStatus;
use App\Models\ReviewComment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Decyzja o rewizji. Pracownik: ProjectPolicy@decideReview; gość: podklasa bez Gate (middleware `fmi.review-guest:approve`).
 * `revision` = numer rewizji, którą decydujący widział (decyzja nie trafi na inną). Poprawki wymagają opisu.
 */
class StoreReviewDecisionRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('decideReview', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'decision' => ['required', Rule::in([ReviewRevisionStatus::Approved->value, ReviewRevisionStatus::ChangesRequested->value])],
			'revision' => ['required', 'integer', 'min:1'],
			'note' => ['nullable', 'required_if:decision,' . ReviewRevisionStatus::ChangesRequested->value, 'string', 'max:' . ReviewComment::BODY_MAX],
			'request_id' => ['required', 'uuid'],
			'confirm' => ['accepted'],
		];
	}

	public function messages(): array
	{
		return [
			'decision.required' => 'Wybierz decyzję.',
			'decision.in' => 'Nieznana decyzja.',
			'revision.required' => 'Formularz jest nieaktualny — odśwież stronę.',
			'note.required_if' => 'Opisz, co trzeba poprawić.',
			'note.max' => 'Opis może mieć najwyżej ' . ReviewComment::BODY_MAX . ' znaków.',
			'request_id.required' => 'Formularz jest nieaktualny — odśwież stronę.',
			'request_id.uuid' => 'Formularz jest nieaktualny — odśwież stronę.',
			'confirm.accepted' => 'Potwierdź, że decyzja dotyczy wskazanej rewizji.',
		];
	}

	public function decision(): ReviewRevisionStatus
	{
		return ReviewRevisionStatus::from($this->validated('decision'));
	}

	protected function prepareForValidation(): void
	{
		$this->errorBag = 'decision';
	}
}
