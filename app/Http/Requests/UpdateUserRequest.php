<?php

namespace App\Http\Requests;

use App\Services\RoleManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
	public function authorize(): bool
	{
		return Gate::allows('manage-users');
	}

	public function rules(): array
	{
		return [
			'first_name' => ['required', 'string', 'max:80'],
			'last_name' => ['required', 'string', 'max:80'],
			'role' => ['nullable', 'string', Rule::in(array_keys(RoleManager::APP_ROLES))],
			'capabilities' => ['nullable', 'array'],
			'capabilities.*' => ['string', Rule::in(array_keys(RoleManager::EXTRA_CAPABILITIES))],
		];
	}

	public function attributes(): array
	{
		return [
			'first_name' => 'imię',
			'last_name' => 'nazwisko',
			'role' => 'rola',
		];
	}

	protected function prepareForValidation(): void
	{
		$this->merge([
			'first_name' => is_string($this->first_name) ? trim(sanitize_text_field($this->first_name)) : $this->first_name,
			'last_name' => is_string($this->last_name) ? trim(sanitize_text_field($this->last_name)) : $this->last_name,
		]);
	}
}
