<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Nowy link recenzji dla klienta. Autoryzacja: ProjectPolicy@manageClientAccess (`fmi_manage_client_access`).
 * Link recenzji nie wygasa (decyzja właściciela z 2026-11-04) — działa do unieważnienia. Odczyt zawsze, komentarz
 * i akceptacja — jawnie.
 */
class StoreReviewLinkRequest extends FormRequest
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
			'can_comment' => ['boolean'],
			'can_approve' => ['boolean'],
			'code' => ['nullable', 'string', 'min:6', 'max:64'],
		];
	}

	public function messages(): array
	{
		return [
			'code.min' => 'Kod dostępu musi mieć co najmniej 6 znaków.',
			'code.max' => 'Kod dostępu może mieć najwyżej 64 znaki.',
		];
	}

	public function label(): ?string
	{
		return trim((string) $this->validated('label')) ?: null;
	}

	public function code(): ?string
	{
		$code = trim((string) $this->validated('code'));

		return $code === '' ? null : $code;
	}
}
