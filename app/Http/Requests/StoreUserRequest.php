<?php

namespace App\Http\Requests;

use App\Services\RoleManager;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
			'email' => ['required', 'string', 'max:100', 'email:rfc', function (string $attribute, mixed $value, Closure $fail) {
				if (! is_email((string) $value)) {
					$fail('Podaj poprawny adres e-mail.');
				} elseif (email_exists((string) $value)) {
					$fail('Ten adres e-mail jest już używany przez inne konto WordPress.');
				}
			}],
			'role' => ['required', 'string', Rule::in(array_keys(RoleManager::APP_ROLES))],
			'capabilities' => ['nullable', 'array'],
			'capabilities.*' => ['string', Rule::in(array_keys(RoleManager::EXTRA_CAPABILITIES))],
		];
	}

	public function attributes(): array
	{
		return [
			'first_name' => 'imię',
			'last_name' => 'nazwisko',
			'email' => 'e-mail',
			'role' => 'rola',
		];
	}

	protected function prepareForValidation(): void
	{
		$this->merge([
			'first_name' => is_string($this->first_name) ? trim(sanitize_text_field($this->first_name)) : $this->first_name,
			'last_name' => is_string($this->last_name) ? trim(sanitize_text_field($this->last_name)) : $this->last_name,
			'email' => is_string($this->email) ? mb_strtolower(trim($this->email)) : $this->email,
		]);
	}
}
