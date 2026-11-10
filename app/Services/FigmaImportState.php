<?php

namespace App\Services;

use App\Enums\ElementSource;
use App\Enums\FieldType;
use App\Models\Field;
use App\Models\Section;
use Illuminate\Support\Collection;

/**
 * Stan sekcji i pól podstrony podczas planowania importu z Figmy: dopasowanie elementów `figma`
 * do propozycji (każdy co najwyżej raz) i wyszukanie tych, których w propozycji zabrakło.
 */
class FigmaImportState
{
	/** @var array<int, true> */
	private array $claimedSections = [];

	/** @var array<int, true> */
	private array $claimedFields = [];

	/** @var Collection<int, Field> */
	private Collection $byId;

	/**
	 * @param array<int, int|string> $filled ID pól z zapisaną treścią (jako klucze)
	 */
	public function __construct(private Collection $sections, private Collection $fields, private array $filled = [])
	{
		$this->byId = $fields->keyBy('id');
	}

	public function hasContent(Field $field): bool
	{
		return isset($this->filled[$field->id]);
	}

	/**
	 * Klucz dopasowania pola: ID warstwy + rola (kontener / pole), bo repeater listy tekstów
	 * i jego pole potomne wskazują tę samą warstwę.
	 */
	public static function key(?string $nodeId, bool $container): string
	{
		return ($container ? 'c:' : 'f:') . $nodeId;
	}

	public static function fieldKey(Field $field): string
	{
		return self::key($field->figma_node_id, $field->type->isContainer());
	}

	public function claimSection(string $nodeId): ?Section
	{
		$section = $this->sections->first(
			fn (Section $section) => $section->figma_node_id === $nodeId && ! isset($this->claimedSections[$section->id]),
		);

		if ($section) {
			$this->claimedSections[$section->id] = true;
		}

		return $section;
	}

	/**
	 * Aktywne pole wygrywa z zarchiwizowanym o tym samym kluczu.
	 */
	public function claimField(string $key, callable $accepts): ?Field
	{
		$field = $this->fields
			->filter(fn (Field $field) => $field->source === ElementSource::Figma
				&& $field->figma_node_id !== null
				&& ! isset($this->claimedFields[$field->id])
				&& self::fieldKey($field) === $key
				&& $accepts($field))
			->sortBy(fn (Field $field) => [$field->archived_at !== null ? 1 : 0, $field->id])
			->first();

		if ($field) {
			$this->claimedFields[$field->id] = true;
		}

		return $field;
	}

	/**
	 * Repeater, którego wzorzec zniknął z Figmy: rozpoznajemy go po pozostałych elementach listy.
	 *
	 * @param string[] $items ID elementów z propozycji
	 */
	public function claimRepeaterByItems(array $items, callable $accepts): ?Field
	{
		$field = $this->fields->first(fn (Field $field) => $field->type === FieldType::Repeater
			&& $field->source === ElementSource::Figma
			&& ! isset($this->claimedFields[$field->id])
			&& array_intersect(array_column($field->figma_meta['items'] ?? [], 'node_id'), $items) !== []
			&& $accepts($field));

		if ($field) {
			$this->claimedFields[$field->id] = true;
		}

		return $field;
	}

	/**
	 * Pole repeatera po zmianie wzorca: ID warstwy jest nowe, więc liczy się nazwa warstwy i rola.
	 */
	public function claimSibling(int $parentId, string $nodeName, bool $container): ?Field
	{
		$field = $this->fields->first(fn (Field $field) => (int) $field->parent_id === $parentId
			&& $field->source === ElementSource::Figma
			&& ! isset($this->claimedFields[$field->id])
			&& $field->type->isContainer() === $container
			&& ($field->figma_meta['node_name'] ?? null) === $nodeName);

		if ($field) {
			$this->claimedFields[$field->id] = true;
		}

		return $field;
	}

	/**
	 * Klucz najbliższego repeatera nadrzędnego istniejącego pola (null poza repeaterem).
	 */
	public function repeaterKey(Field $field): ?string
	{
		$parent = $field->parent_id ? $this->byId->get($field->parent_id) : null;

		while ($parent && $parent->type !== FieldType::Repeater) {
			$parent = $parent->parent_id ? $this->byId->get($parent->parent_id) : null;
		}

		return $parent ? self::fieldKey($parent) : null;
	}

	public function unclaimedSections(): Collection
	{
		return $this->sections->filter(fn (Section $section) => $section->source === ElementSource::Figma
			&& $section->archived_at === null
			&& ! isset($this->claimedSections[$section->id]))->values();
	}

	public function unclaimedFields(): Collection
	{
		return $this->fields->filter(fn (Field $field) => $field->source === ElementSource::Figma
			&& $field->archived_at === null
			&& ! isset($this->claimedFields[$field->id]))->values();
	}

	public function manualFieldsIn(Section $section): int
	{
		return $this->fields->filter(fn (Field $field) => (int) $field->section_id === (int) $section->id
			&& $field->source === ElementSource::Manual
			&& $field->archived_at === null)->count();
	}

	/**
	 * Także zarchiwizowane: przeniesienie kontenera zmieniłoby sekcję i poziom wszystkich potomków.
	 */
	public function holdsManual(Field $field): bool
	{
		foreach ($this->fields->where('parent_id', $field->id) as $child) {
			if ($child->source === ElementSource::Manual || $this->holdsManual($child)) {
				return true;
			}
		}

		return false;
	}

	public function manualDescendants(Field $field): int
	{
		$count = 0;

		foreach ($this->fields->where('parent_id', $field->id) as $child) {
			if ($child->archived_at !== null) {
				continue;
			}

			$count += ($child->source === ElementSource::Manual ? 1 : 0) + $this->manualDescendants($child);
		}

		return $count;
	}
}
