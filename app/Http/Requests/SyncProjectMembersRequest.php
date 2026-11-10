<?php

namespace App\Http\Requests;

use App\Support\Staff;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Pełna lista dodatkowych pracowników projektu. Autoryzacja: ProjectPolicy@manageMembers.
 */
class SyncProjectMembersRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('manageMembers', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'member_ids' => ['nullable', 'array', 'max:50'],
			'member_ids.*' => ['integer', 'distinct', function (string $attribute, mixed $value, Closure $fail) {
				if (! Staff::isStaff((int) $value)) {
					$fail('Wybrana osoba nie jest pracownikiem z dostępem do aplikacji.');
				}
			}],
		];
	}

	public function attributes(): array
	{
		return ['member_ids' => 'pracownicy'];
	}

	/**
	 * @return int[]
	 */
	public function memberIds(): array
	{
		return array_map('intval', $this->validated()['member_ids'] ?? []);
	}
}
