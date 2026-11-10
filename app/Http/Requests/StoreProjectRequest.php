<?php

namespace App\Http\Requests;

use App\Integrations\Figma\FigmaUrl;
use App\Models\Project;
use App\Support\Staff;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreProjectRequest extends FormRequest
{
	use ValidatesProjectModules;

	public function authorize(): bool
	{
		return Gate::allows('create', Project::class);
	}

	public function rules(): array
	{
		$staff = function (string $attribute, mixed $value, Closure $fail) {
			if (! Staff::isStaff((int) $value)) {
				$fail('Wybrana osoba nie jest pracownikiem z dostępem do aplikacji.');
			}
		};

		return [
			'name' => ['required', 'string', 'max:190'],
			'client_name' => ['nullable', 'string', 'max:190'],
			'manager_id' => ['nullable', 'integer', $staff],
			'member_ids' => ['nullable', 'array', 'max:50'],
			'member_ids.*' => ['integer', 'distinct', $staff],
			'figma_url' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail) {
				if (! FigmaUrl::isValid($value)) {
					$fail('Podaj link do pliku Figmy (figma.com/design/… lub figma.com/file/…).');
				}
			}],
		] + $this->moduleRules();
	}

	public function messages(): array
	{
		return $this->moduleMessages();
	}

	public function attributes(): array
	{
		return [
			'name' => 'nazwa projektu',
			'client_name' => 'nazwa klienta',
			'manager_id' => 'opiekun',
			'member_ids' => 'pracownicy',
			'figma_url' => 'link do Figmy',
			'modules' => 'moduły',
		];
	}

	protected function prepareForValidation(): void
	{
		$this->merge([
			'name' => is_string($this->name) ? trim($this->name) : $this->name,
			'client_name' => is_string($this->client_name) ? trim($this->client_name) : $this->client_name,
			'figma_url' => is_string($this->figma_url) ? trim($this->figma_url) : $this->figma_url,
		]);
	}
}
