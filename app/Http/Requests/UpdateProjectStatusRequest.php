<?php

namespace App\Http\Requests;

use App\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Zmiana statusu projektu (w tym przeniesienie do archiwum). Autoryzacja: ProjectPolicy@update.
 */
class UpdateProjectStatusRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('update', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'status' => ['required', Rule::enum(ProjectStatus::class)],
		];
	}

	public function attributes(): array
	{
		return ['status' => 'status'];
	}

	public function status(): ProjectStatus
	{
		return ProjectStatus::from($this->validated()['status']);
	}
}
