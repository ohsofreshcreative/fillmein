<?php

namespace App\Http\Requests;

use App\Enums\ProjectStatus;
use App\Integrations\Figma\FigmaUrl;
use App\Models\Project;
use App\Support\Staff;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Edycja ustawień projektu. Autoryzacja: ProjectPolicy@update (autor lub opiekun
 * z capability `fmi_create_projects`; Administrator przez `before`).
 */
class UpdateProjectRequest extends FormRequest
{
	use ValidatesProjectModules;

	public function authorize(): bool
	{
		Gate::authorize('update', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'name' => ['required', 'string', 'max:190'],
			'client_name' => ['nullable', 'string', 'max:190'],
			'status' => ['required', Rule::enum(ProjectStatus::class)],
			'manager_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
				if (! Staff::isStaff((int) $value)) {
					$fail('Wybrana osoba nie jest pracownikiem z dostępem do aplikacji.');
				}
			}],
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
			'status' => 'status',
			'manager_id' => 'opiekun',
			'figma_url' => 'link do pliku Figmy',
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

	public function project(): Project
	{
		return $this->route('project');
	}
}
