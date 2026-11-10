<?php

namespace App\Http\Requests;

use App\Integrations\Figma\FigmaUrl;
use App\Models\Project;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Nowa podstrona projektu. Autoryzacja: ProjectPolicy@managePages.
 *
 * `figma_frame_url` musi wskazywać KONKRETNY frame (parametr `node-id`) — ustalenie produktowe:
 * każda podstrona ma własny frame, a nie link do całego pliku Figmy.
 * Przynależność `parent_id` do projektu weryfikuje PageService (ochrona przed IDOR).
 */
class StorePageRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('managePages', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'name' => ['required', 'string', 'max:190'],
			'slug' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
			'parent_id' => ['nullable', 'integer', 'min:1'],
			'client_can_duplicate' => ['nullable', 'boolean'],
			'figma_frame_url' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail) {
				if (! FigmaUrl::isFrame($value)) {
					$fail('Podaj link do konkretnego frame\'u Figmy (adres musi zawierać parametr node-id).');
				}
			}],
		];
	}

	public function attributes(): array
	{
		return [
			'name' => 'nazwa podstrony',
			'slug' => 'slug',
			'parent_id' => 'podstrona nadrzędna',
			'figma_frame_url' => 'link do frame\'u Figmy',
			'client_can_duplicate' => 'zgoda na duplikowanie przez klienta',
		];
	}

	public function messages(): array
	{
		return [
			'slug.regex' => 'Slug może zawierać tylko małe litery, cyfry i pojedyncze myślniki.',
		];
	}

	protected function prepareForValidation(): void
	{
		$this->merge([
			'name' => is_string($this->name) ? trim($this->name) : $this->name,
			'slug' => is_string($this->slug) ? mb_strtolower(trim($this->slug)) : $this->slug,
			'figma_frame_url' => is_string($this->figma_frame_url) ? trim($this->figma_frame_url) : $this->figma_frame_url,
			'client_can_duplicate' => $this->boolean('client_can_duplicate'),
		]);
	}

	/**
	 * @return array{name: string, slug: ?string, parent_id: ?int, figma_frame_url: ?string, client_can_duplicate: bool}
	 */
	public function pageData(): array
	{
		$data = $this->validated();

		return [
			'name' => $data['name'],
			'slug' => $data['slug'] ?? null,
			'parent_id' => isset($data['parent_id']) ? (int) $data['parent_id'] : null,
			'figma_frame_url' => $data['figma_frame_url'] ?? null,
			'client_can_duplicate' => (bool) ($data['client_can_duplicate'] ?? false),
		];
	}

	public function project(): Project
	{
		return $this->route('project');
	}
}
