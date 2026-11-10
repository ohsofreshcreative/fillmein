<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'login' => ['required', 'string', 'max:200'],
			'password' => ['required', 'string', 'max:200'],
			'remember' => ['nullable', 'boolean'],
			'redirect_to' => ['nullable', 'string', 'max:500'],
		];
	}

	public function attributes(): array
	{
		return [
			'login' => 'login lub e-mail',
			'password' => 'hasło',
		];
	}
}
