<?php

namespace App\Services;

/**
 * Walidacja eksportu: schemat `resources/schemas/fmi-export.schema.json` i spójność odwołań.
 *
 * Własny interpreter podzbioru JSON Schema (type, required, properties, additionalProperties, items, enum, const,
 * pattern, minimum, minLength, oneOf, $ref), bo `vendor/` nie ma walidatora, a nowa zależność dla tych kilku
 * słów kluczowych się nie opłaca. Schemat zostaje zwykłym plikiem JSON Schema, czytelnym też dla narzędzi zewnętrznych.
 */
class ExportValidator
{
	/** Plik w `resources/schemas/`; podklasa podmienia schemat i reguły spójności. */
	protected const SCHEMA = 'fmi-export';

	private array $schema;

	public function __construct()
	{
		$this->schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/schemas/' . static::SCHEMA . '.schema.json'), true, 64, JSON_THROW_ON_ERROR);
	}

	/**
	 * @return string[] lista błędów ze ścieżką do miejsca w dokumencie; pusta, gdy eksport jest poprawny
	 */
	public function validate(array $payload): array
	{
		// Normalizacja przez JSON: obiekty PHP stają się tablicami, jak po wczytaniu pliku.
		$document = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		$errors = $this->check($document, $this->schema, '');

		return $errors === [] ? $this->references($document) : $errors;
	}

	private function check(mixed $value, array $rule, string $path): array
	{
		if (isset($rule['$ref'])) {
			return $this->check($value, $this->schema['definitions'][substr($rule['$ref'], strlen('#/definitions/'))], $path);
		}

		$errors = [];

		if (isset($rule['type']) && ! $this->hasType($value, (array) $rule['type'])) {
			return [sprintf('%s: oczekiwano typu %s', $path ?: '/', implode('|', (array) $rule['type']))];
		}

		if (array_key_exists('const', $rule) && $value !== $rule['const']) {
			$errors[] = sprintf('%s: oczekiwano wartości %s', $path ?: '/', json_encode($rule['const']));
		}

		if (isset($rule['enum']) && ! in_array($value, $rule['enum'], true)) {
			$errors[] = sprintf('%s: wartość spoza listy', $path ?: '/');
		}

		if (is_string($value)) {
			if (isset($rule['pattern']) && ! preg_match('~' . str_replace('~', '\~', $rule['pattern']) . '~u', $value)) {
				$errors[] = sprintf('%s: nieprawidłowy format', $path);
			}

			if (isset($rule['minLength']) && mb_strlen($value) < $rule['minLength']) {
				$errors[] = sprintf('%s: wartość za krótka', $path);
			}
		}

		if ((is_int($value) || is_float($value)) && isset($rule['minimum']) && $value < $rule['minimum']) {
			$errors[] = sprintf('%s: wartość poniżej minimum', $path);
		}

		if (is_array($value) && ! array_is_list($value) || (is_array($value) && $value === [] && $this->allows($rule, 'object'))) {
			array_push($errors, ...$this->checkObject($value, $rule, $path));
		}

		if (is_array($value) && array_is_list($value) && isset($rule['items'])) {
			foreach ($value as $index => $item) {
				array_push($errors, ...$this->check($item, $rule['items'], "{$path}/{$index}"));
			}
		}

		if (isset($rule['oneOf'])) {
			array_push($errors, ...$this->checkOneOf($value, $rule['oneOf'], $path));
		}

		return $errors;
	}

	private function checkObject(array $value, array $rule, string $path): array
	{
		$errors = [];

		foreach ($rule['required'] ?? [] as $key) {
			if (! array_key_exists($key, $value)) {
				$errors[] = sprintf('%s: brak klucza „%s”', $path ?: '/', $key);
			}
		}

		foreach ($value as $key => $item) {
			if (isset($rule['properties'][$key])) {
				array_push($errors, ...$this->check($item, $rule['properties'][$key], "{$path}/{$key}"));
			} elseif (($rule['additionalProperties'] ?? true) === false) {
				$errors[] = sprintf('%s: niedozwolony klucz „%s”', $path ?: '/', $key);
			}
		}

		return $errors;
	}

	/**
	 * Przy niedopasowaniu zwraca błędy wariantu najbliższego danym, a nie zsumowane błędy wszystkich.
	 */
	private function checkOneOf(mixed $value, array $variants, string $path): array
	{
		$best = null;
		$matches = 0;

		foreach ($variants as $variant) {
			$errors = $this->check($value, $variant, $path);
			$matches += $errors === [] ? 1 : 0;

			if ($best === null || count($errors) < count($best)) {
				$best = $errors;
			}
		}

		return match (true) {
			$matches === 1 => [],
			$matches > 1 => [sprintf('%s: pasuje do kilku wariantów', $path)],
			default => $best,
		};
	}

	private function allows(array $rule, string $type): bool
	{
		return ! isset($rule['type']) || in_array($type, (array) $rule['type'], true);
	}

	private function hasType(mixed $value, array $types): bool
	{
		foreach ($types as $type) {
			$matches = match ($type) {
				'string' => is_string($value),
				'integer' => is_int($value),
				'number' => is_int($value) || is_float($value),
				'boolean' => is_bool($value),
				'null' => $value === null,
				'array' => is_array($value) && array_is_list($value),
				'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
				default => false,
			};

			if ($matches) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Spójność, której schemat nie wyrazi: unikalne ID i odwołania, typy wartości zgodne z polem, wiersze w swoim repeaterze.
	 */
	protected function references(array $document): array
	{
		$errors = [];
		$pages = $this->index($document['pages'], 'pages', $errors);
		$sections = $this->index($document['sections'], 'sections', $errors);
		$fields = $this->index($document['fields'], 'fields', $errors);
		$assets = $this->index($document['assets'], 'assets', $errors);

		foreach ($document['pages'] as $index => $page) {
			if ($page['parent_id'] !== null && ! isset($pages[$page['parent_id']])) {
				$errors[] = "/pages/{$index}/parent_id: nieznana podstrona";
			}
		}

		foreach ($document['sections'] as $index => $section) {
			if (! isset($pages[$section['page_id']])) {
				$errors[] = "/sections/{$index}/page_id: nieznana podstrona";
			}
		}

		foreach ($document['fields'] as $index => $field) {
			if (! isset($sections[$field['section_id']])) {
				$errors[] = "/fields/{$index}/section_id: nieznana sekcja";
			}

			if ($field['parent_id'] !== null && ! isset($fields[$field['parent_id']])) {
				$errors[] = "/fields/{$index}/parent_id: nieznane pole";
			}
		}

		foreach ($document['values'] as $index => $value) {
			$this->checkValue($value, null, "/values/{$index}", $fields, $assets, $errors);
		}

		return $errors;
	}

	private function index(array $items, string $name, array &$errors): array
	{
		$known = [];

		foreach ($items as $index => $item) {
			if (isset($known[$item['id']])) {
				$errors[] = "/{$name}/{$index}/id: powtórzone ID";
			}

			$known[$item['id']] = $item;
		}

		return $known;
	}

	private function checkValue(array $value, ?string $repeater, string $path, array $fields, array $assets, array &$errors): void
	{
		$field = $fields[$value['field_id']] ?? null;

		if (! $field) {
			$errors[] = "{$path}/field_id: nieznane pole";

			return;
		}

		if ($field['type'] !== $value['field_type']) {
			$errors[] = "{$path}/field_type: typ inny niż w definicji pola";

			return;
		}

		if ($this->closestRepeater($field, $fields) !== $repeater) {
			$errors[] = "{$path}/field_id: pole nie należy do tego poziomu wierszy";
		}

		foreach (array_filter((array) ($field['type'] === 'image' || $field['type'] === 'gallery' ? $value['value'] : [])) as $asset) {
			if (! isset($assets[$asset])) {
				$errors[] = "{$path}/value: nieznany plik";
			}
		}

		if ($field['type'] === 'repeater') {
			foreach ($value['value'] as $rowIndex => $row) {
				foreach ($row['values'] as $childIndex => $child) {
					$this->checkValue($child, $field['id'], "{$path}/value/{$rowIndex}/values/{$childIndex}", $fields, $assets, $errors);
				}
			}
		}
	}

	private function closestRepeater(array $field, array $fields): ?string
	{
		$parent = $fields[$field['parent_id'] ?? ''] ?? null;

		while ($parent && $parent['type'] !== 'repeater') {
			$parent = $fields[$parent['parent_id'] ?? ''] ?? null;
		}

		return $parent['id'] ?? null;
	}
}
