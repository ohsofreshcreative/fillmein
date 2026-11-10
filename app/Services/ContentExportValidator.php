<?php

namespace App\Services;

/**
 * Walidacja uniwersalnego `content.json` (schemat `resources/schemas/fmi-content.schema.json`) tym samym interpreterem
 * co eksport ACF. Spójność poza schematem: unikalne ID, rodzic podstrony w eksporcie, odwołania do plików z `media`.
 */
class ContentExportValidator extends ExportValidator
{
	protected const SCHEMA = 'fmi-content';

	protected function references(array $document): array
	{
		$errors = [];
		$seen = [];
		$media = [];

		foreach ($document['media'] as $index => $item) {
			if (isset($media[$item['id']])) {
				$errors[] = "/media/{$index}/id: powtórzone ID";
			}

			if ($item['path'] !== sprintf('media/%s.%s', $item['sha256'], pathinfo($item['path'], PATHINFO_EXTENSION))) {
				$errors[] = "/media/{$index}/path: nazwa pliku inna niż suma SHA-256";
			}

			$media[$item['id']] = $item['path'];
		}

		$pages = array_column($document['pages'], 'id', 'id');

		foreach ($document['pages'] as $index => $page) {
			$this->unique($page['id'], "/pages/{$index}", $seen, $errors);

			if ($page['parent_id'] !== null && ! isset($pages[$page['parent_id']])) {
				$errors[] = "/pages/{$index}/parent_id: podstrona spoza eksportu";
			}

			foreach ($page['sections'] as $sectionIndex => $section) {
				$path = "/pages/{$index}/sections/{$sectionIndex}";
				$this->unique($section['id'], $path, $seen, $errors);
				$this->fields($section['fields'], "{$path}/fields", $media, $seen, $errors);
			}
		}

		return $errors;
	}

	/**
	 * Definicja pola powtarza się w każdym wierszu repeatera, więc unikalność dotyczy wierszy, nie pól.
	 */
	private function fields(array $fields, string $path, array $media, array &$seen, array &$errors): void
	{
		foreach ($fields as $index => $field) {
			$here = "{$path}/{$index}";

			$refs = match ($field['type']) {
				'image' => $field['value'] === null ? [] : [$field['value']],
				'gallery' => $field['value'],
				default => [],
			};

			foreach ($refs as $ref) {
				if (($media[$ref['media_id']] ?? null) !== $ref['path']) {
					$errors[] = "{$here}/value: plik spoza listy media";
				}
			}

			if ($field['type'] === 'group') {
				$this->fields($field['fields'], "{$here}/fields", $media, $seen, $errors);
			}

			if ($field['type'] === 'repeater') {
				foreach ($field['rows'] as $rowIndex => $row) {
					$this->unique($row['id'], "{$here}/rows/{$rowIndex}", $seen, $errors);
					$this->fields($row['fields'], "{$here}/rows/{$rowIndex}/fields", $media, $seen, $errors);
				}
			}
		}
	}

	private function unique(string $id, string $path, array &$seen, array &$errors): void
	{
		if (isset($seen[$id])) {
			$errors[] = "{$path}/id: powtórzone ID";
		}

		$seen[$id] = true;
	}
}
