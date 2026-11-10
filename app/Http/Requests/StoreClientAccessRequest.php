<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Wygenerowanie lub regeneracja linku klienta. Autoryzacja: ProjectPolicy@manageClientAccess.
 * Linki Treści nie wygasają (decyzja właściciela z 2026-11-05) — działają do unieważnienia lub regeneracji.
 */
class StoreClientAccessRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('manageClientAccess', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'label' => ['nullable', 'string', 'max:190'],
		];
	}

	public function attributes(): array
	{
		return ['label' => 'opis linku'];
	}

	public function label(): ?string
	{
		return trim((string) ($this->validated()['label'] ?? '')) ?: null;
	}
}
