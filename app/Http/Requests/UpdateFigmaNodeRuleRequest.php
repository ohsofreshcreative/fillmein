<?php

namespace App\Http\Requests;

use App\Integrations\Figma\FigmaUrl;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Wykluczenie / przywrócenie warstwy frame'u w imporcie z Figmy. Autoryzacja: ProjectPolicy@configureFields.
 * Przynależność warstwy do frame'u tej podstrony sprawdza FigmaImportService::setRule().
 */
class UpdateFigmaNodeRuleRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('configureFields', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			// Także warstwy wewnątrz instancji (`I12:34;56:78`) — analiza proponuje je jako pola.
			'node' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
				if (! FigmaUrl::isLayerId(is_string($value) ? $value : null)) {
					$fail('Nieprawidłowy identyfikator warstwy.');
				}
			}],
			'action' => ['required', 'string', 'in:include,exclude,reset'],
		];
	}

	public function messages(): array
	{
		return [
			'node.*' => 'Nieprawidłowy identyfikator warstwy.',
			'action.*' => 'Nieprawidłowa operacja.',
		];
	}

	public function node(): string
	{
		return (string) $this->validated()['node'];
	}

	public function action(): string
	{
		return (string) $this->validated()['action'];
	}
}
