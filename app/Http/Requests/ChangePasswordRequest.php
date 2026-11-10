<?php

namespace App\Http\Requests;

use App\Support\PasswordRules;
use App\Support\Staff;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
	/**
	 * Osobny worek błędów — profil ma dwa formularze na jednej stronie.
	 */
	protected $errorBag = 'password';

	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		$user = Staff::current();

		return [
			'current_password' => ['required', 'string', 'max:200'],
			'password' => [...PasswordRules::rules([$user?->user_login, $user?->user_email]), 'different:current_password'],
		];
	}

	public function attributes(): array
	{
		return [
			'current_password' => 'aktualne hasło',
			'password' => 'nowe hasło',
		];
	}
}
