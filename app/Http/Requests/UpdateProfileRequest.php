<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'first_name' => ['required', 'string', 'max:80'],
			'last_name' => ['required', 'string', 'max:80'],
		];
	}

	public function attributes(): array
	{
		return [
			'first_name' => 'imię',
			'last_name' => 'nazwisko',
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
