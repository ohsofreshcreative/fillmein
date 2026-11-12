<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Klient dodaje kolejną podstronę na wzór istniejącej. Autoryzację daje ważny token (middleware `fmi.client`);
 * zgodę na podstronie (`client_can_duplicate`) i projekt linku sprawdza PageDuplicationService.
 */
class ClientDuplicatePageRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'name' => ['required', 'string', 'max:190'],
		];
	}

	public function attributes(): array
	{
		return ['name' => 'nazwa podstrony'];
	}
}
