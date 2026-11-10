<?php

namespace App\Support;

use App\Models\Field;
use App\Models\FieldValue;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Services\ContentService;
use Illuminate\Support\Collection;

/**
 * Widoczna dla klienta struktura podstrony: tylko aktywne, nieukryte elementy, wczytane jednym kompletem zapytań.
 */
final class ClientStructure
{
	/** @var array<int|string, array<int, string>> */
	private array $labels = [];

	public function __construct(
		public readonly Collection $sections,
		private Collection $fieldsByParent,
		private Collection $values,
		private Collection $rows,
		private Collection $assets,
	) {
	}

	/**
	 * Pola poziomu: dzieci kontenera albo pola sekcji.
	 */
	public function children(Section $section, ?Field $parent = null): Collection
	{
		return $this->fieldsByParent[$parent?->id ?? 'section-' . $section->id] ?? collect();
	}

	/**
	 * Etykieta pola dla klienta. Zależy od pól obok, więc liczymy ją dla całego poziomu naraz.
	 */
	public function label(Field $field): string
	{
		$level = $field->parent_id ?: 'section-' . $field->section_id;

		return ($this->labels[$level] ??= ClientLabels::siblings($this->fieldsByParent[$level] ?? collect([$field])))[$field->id];
	}

	public function value(Field $field, ?RepeaterRow $row): ?FieldValue
	{
		return $this->values[$field->id . '|' . ($row?->uuid ?? '')] ?? null;
	}

	/**
	 * Pliki pola w zapisanej kolejności.
	 */
	public function assets(Field $field, ?RepeaterRow $row): Collection
	{
		return collect(ContentService::rowList($this->value($field, $row)))
			->map(fn (string $uuid) => $this->assets[$uuid] ?? null)
			->filter()
			->values();
	}

	/**
	 * Wiersze repeatera w kolejności zapisanej w jego wartości. `$parentRow` — wiersz repeatera nadrzędnego.
	 */
	public function rows(Field $repeater, ?RepeaterRow $parentRow): Collection
	{
		$order = array_flip(ContentService::rowList($this->value($repeater, $parentRow)));

		return ($this->rows[$repeater->id . '|' . ($parentRow?->id ?? 0)] ?? collect())
			->filter(fn (RepeaterRow $row) => isset($order[$row->uuid]))
			->sortBy(fn (RepeaterRow $row) => $order[$row->uuid])
			->values();
	}
}
