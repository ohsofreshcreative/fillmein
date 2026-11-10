<?php

namespace App\Http\Requests;

use App\Models\SiteMapping;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * „Dopasuj automatycznie” i „Przywróć automat”. Autoryzacja: ProjectPolicy@export.
 */
class SiteMappingActionRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('export', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'restore' => ['sometimes', 'string', 'regex:/^(' . SiteMapping::PAGE . '|' . SiteMapping::SECTION . '|' . SiteMapping::FIELD . '):\d{1,19}$/'],
			'strona' => ['sometimes', 'integer'],
		];
	}

	/** @return array{0: string, 1: int}|null */
	public function restoreTarget(): ?array
	{
		if (! $this->filled('restore')) {
			return null;
		}

		[$type, $id] = explode(':', (string) $this->input('restore'));

		return [$type, (int) $id];
	}
}
