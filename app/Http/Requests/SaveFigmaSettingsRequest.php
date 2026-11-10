<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveFigmaSettingsRequest extends FormRequest
{
	public function authorize(): bool
	{
		return Gate::allows('manage-integrations');
	}

	public function rules(): array
	{
		return [
			'client_id' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9_-]+$/'],
			'client_secret' => ['nullable', 'string', 'max:500', 'regex:/^\S+$/'],
		];
	}

	public function attributes(): array
	{
		return ['client_id' => 'Client ID', 'client_secret' => 'Client Secret'];
	}
}
