<?php

namespace App\Http\Requests;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class SetPasswordRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		$reset = (array) $this->session()->get('fmi_password_reset', []);
		$user = isset($reset['login']) ? get_user_by('login', $reset['login']) : null;

		return [
			'password' => PasswordRules::rules($user ? [$user->user_login, $user->user_email] : []),
		];
	}

	public function attributes(): array
	{
		return [
			'password' => 'hasło',
		];
	}
}
