<?php

namespace App\Support;

use App\Enums\FieldType;
use App\Models\Field;
use App\Models\Page;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Services\ContentService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Postęp wypełnienia treści — liczony z rzeczywistych danych, od aktywnych korzeni w dół drzewa,
 * więc elementy archiwalne i ukryte (także przez rodzica, sekcję lub podstronę) są pominięte.
 *
 * Mianownik: pola wymagane. Projekt bez wymagań liczy każde pole raz (pole w repeaterze: wypełnione,
 * gdy ma treść w dowolnym wierszu), żeby pasek nadal coś mówił. `required` mówi, który wariant obowiązuje.
 * Sposób liczenia jest wspólny dla całego projektu, dzięki czemu sekcje i podstrony sumują się do wyniku.
 *
 * Repeater przy polach wymaganych: każdy wiersz liczy swoje pola osobno. Brakujące wiersze do `min_items`
 * (pole wymagane: co najmniej jeden) to niewypełnione jednostki. Wiersz bez wymaganych pól, potrzebny
 * do minimum, liczy się jako jedna jednostka.
 *
 * Grupy i repeatery nie mają własnej wartości. Obliczenia są wsadowe: stała liczba zapytań na listę projektów.
 */
final class ProjectProgress
{
	/** @var array<string, EloquentCollection<int, Field>> */
	private Collection $fieldsByParent;

	/** @var array<string, Collection<int, RepeaterRow>> */
	private Collection $rows;

	/** @var array<string, string> */
	private Collection $values;

	/** @var array<string, bool> pliki z tekstem alternatywnym */
	private Collection $described;

	private bool $requiredOnly = false;

	/**
	 * @param int[] $projectIds
	 * @return array<int, array{total: int, filled: int, percent: int, required: bool}>
	 */
	public static function forProjectIds(array $projectIds): array
	{
		return array_map(fn (array $detail) => $detail['project'], self::detailed($projectIds));
	}

	/**
	 * @param EloquentCollection|Collection $projects
	 * @return array<int, array{total: int, filled: int, percent: int, required: bool}>
	 */
	public static function forProjects($projects): array
	{
		return self::forProjectIds(collect($projects)->pluck('id')->all());
	}

	/**
	 * @return array{project: array, pages: array<int, array>, sections: array<int, array>}
	 */
	public static function forProject(int $projectId): array
	{
		return self::detailed([$projectId])[$projectId];
	}

	public static function hint(array $stats): string
	{
		if ($stats['total'] === 0) {
			return 'Brak pól do uzupełnienia';
		}

		return sprintf('%d z %d %s (%d%%)', $stats['filled'], $stats['total'], ($stats['required'] ?? false) ? 'wymaganych pól' : 'pól', $stats['percent']);
	}

	/**
	 * @param int[] $projectIds
	 * @return array<int, array{project: array, pages: array<int, array>, sections: array<int, array>}>
	 */
	private static function detailed(array $projectIds): array
	{
		$projectIds = array_values(array_unique(array_map('intval', $projectIds)));

		if ($projectIds === []) {
			return [];
		}

		$pages = Page::query()->whereIn('project_id', $projectIds)->whereNull('archived_at')->get(['id', 'project_id']);
		$sections = Section::query()->whereIn('page_id', $pages->modelKeys())->whereNull('archived_at')->where('is_hidden', false)->get(['id', 'page_id']);
		$fields = Field::query()
			->whereIn('section_id', $sections->modelKeys())
			->whereNull('archived_at')
			->where('is_hidden', false)
			->get(['id', 'project_id', 'section_id', 'parent_id', 'type', 'is_required', 'settings']);

		$calculator = new self();
		$calculator->fieldsByParent = $fields->groupBy(fn (Field $field) => $field->parent_id ?: 'section-' . $field->section_id);
		$calculator->rows = RepeaterRow::query()
			->whereIn('project_id', $projectIds)
			->get(['id', 'uuid', 'project_id', 'field_id', 'parent_row_id', 'position'])
			->groupBy(fn (RepeaterRow $row) => $row->field_id . '|' . (int) $row->parent_row_id);
		$calculator->values = DB::table('fmi_field_values')
			->whereIn('project_id', $projectIds)
			->whereNotNull('value')
			->get(['field_id', 'row_key', 'value'])
			->filter(fn ($value) => ContentService::hasContent($value->value))
			->flatMap(fn ($value) => [$value->field_id . '|' . $value->row_key => $value->value, $value->field_id . '|*' => $value->value]);
		$calculator->described = DB::table('fmi_assets')
			->whereIn('project_id', $projectIds)
			->whereNotNull('alt')
			->where('alt', '<>', '')
			->pluck('uuid')
			->flip();

		$result = [];

		foreach ($projectIds as $projectId) {
			$projectPages = $pages->where('project_id', $projectId);
			$pageIds = $projectPages->modelKeys();
			$projectSections = $sections->whereIn('page_id', $pageIds);
			$calculator->requiredOnly = $fields->where('project_id', $projectId)->contains(fn (Field $field) => self::demandsContent($field));

			$bySection = [];
			$byPage = array_fill_keys($pageIds, [0, 0]);
			$sum = [0, 0];

			foreach ($projectSections as $section) {
				$units = $calculator->level($calculator->fieldsByParent['section-' . $section->id] ?? collect(), null);

				$bySection[$section->id] = $calculator->stats($units);
				$byPage[$section->page_id] = [$byPage[$section->page_id][0] + $units[0], $byPage[$section->page_id][1] + $units[1]];
				$sum = [$sum[0] + $units[0], $sum[1] + $units[1]];
			}

			$result[$projectId] = [
				'project' => $calculator->stats($sum),
				'pages' => array_map(fn (array $units) => $calculator->stats($units), $byPage),
				'sections' => $bySection,
			];
		}

		return $result;
	}

	private static function demandsContent(Field $field): bool
	{
		return match ($field->type) {
			FieldType::Group => false,
			FieldType::Repeater => $field->is_required || (int) ($field->settings['min_items'] ?? 0) > 0,
			default => (bool) $field->is_required,
		};
	}

	/**
	 * @return array{0: int, 1: int} wypełnione i wszystkie jednostki
	 */
	private function level(Collection $fields, ?RepeaterRow $row): array
	{
		$filled = 0;
		$total = 0;

		foreach ($fields as $field) {
			[$fieldFilled, $fieldTotal] = match ($field->type) {
				FieldType::Group => $this->level($this->fieldsByParent[$field->id] ?? collect(), $row),
				FieldType::Repeater => $this->repeater($field, $row),
				default => $this->leaf($field, $row),
			};

			$filled += $fieldFilled;
			$total += $fieldTotal;
		}

		return [$filled, $total];
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	private function leaf(Field $field, ?RepeaterRow $row): array
	{
		if ($this->requiredOnly && ! $field->is_required) {
			return [0, 0];
		}

		$key = $this->requiredOnly ? ($row?->uuid ?? '') : '*';

		$value = $this->values[$field->id . '|' . $key] ?? null;

		return [$value !== null && $this->complete($field, $value) ? 1 : 0, 1];
	}

	/**
	 * Galeria potrzebuje `min_items` zdjęć, a pole z wymaganym tekstem alternatywnym opisu każdego z nich.
	 */
	private function complete(Field $field, string $value): bool
	{
		if (! $field->type->isMedia()) {
			return true;
		}

		$list = (array) json_decode($value, true);

		if (count($list) < max(1, (int) ($field->settings['min_items'] ?? 0))) {
			return false;
		}

		return empty($field->settings['require_alt']) || collect($list)->every(fn ($uuid) => isset($this->described[$uuid]));
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	private function repeater(Field $field, ?RepeaterRow $parentRow): array
	{
		$children = $this->fieldsByParent[$field->id] ?? collect();

		if (! $this->requiredOnly) {
			return $this->level($children, null);
		}

		$rows = ($this->rows[$field->id . '|' . (int) $parentRow?->id] ?? collect())->values();
		$minimum = max((int) ($field->settings['min_items'] ?? 0), $field->is_required ? 1 : 0);
		$slots = max($minimum, $rows->count());
		$filled = 0;
		$total = 0;

		for ($index = 0; $index < $slots; $index++) {
			$row = $rows[$index] ?? null;
			// Brakujący wiersz: te same pola, ale bez wartości i podwierszy.
			[$slotFilled, $slotTotal] = $this->level($children, $row ?? new RepeaterRow());

			if ($slotTotal === 0 && $index < $minimum) {
				[$slotFilled, $slotTotal] = [$row ? 1 : 0, 1];
			}

			$filled += $slotFilled;
			$total += $slotTotal;
		}

		return [$filled, $total];
	}

	/**
	 * @param array{0: int, 1: int} $units
	 * @return array{total: int, filled: int, percent: int, required: bool}
	 */
	private function stats(array $units): array
	{
		[$filled, $total] = $units;

		return [
			'total' => $total,
			'filled' => $filled,
			'percent' => $total > 0 ? (int) floor($filled / $total * 100) : 0,
			'required' => $this->requiredOnly,
		];
	}
}
