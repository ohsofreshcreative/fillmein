<?php

namespace App\Http\Requests;

use App\Services\SiteMappingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Wgranie schematu strony (JSON z `wp fmi-sync schema`). Autoryzacja: ProjectPolicy@export.
 * Format i zawartość sprawdza SiteMappingService::parse().
 */
class UploadSiteSchemaRequest extends FormRequest
{
	public function authorize(): bool
	{
		Gate::authorize('export', $this->route('project'));

		return true;
	}

	public function rules(): array
	{
		return [
			'schema' => ['required', 'file', 'extensions:json', 'max:' . (SiteMappingService::MAX_BYTES / 1024)],
		];
	}

	public function messages(): array
	{
		return [
			'schema.required' => 'Wybierz plik schematu (JSON).',
			'schema.file' => 'Wybierz plik schematu (JSON).',
			'schema.extensions' => 'Schemat strony to plik .json wygenerowany przez wtyczkę Fill Me In Sync.',
			'schema.max' => 'Plik schematu jest za duży (limit 10 MB).',
		];
	}
}
