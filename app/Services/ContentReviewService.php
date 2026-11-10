<?php

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\FieldType;
use App\Models\Field;
use App\Models\FieldValue;
use App\Models\Page;
use App\Models\Project;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Support\Actor;
use App\Support\ClientStructure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use WP_User;

/**
 * Przegląd treści przez zespół: płaska lista pól projektu ze statusem i oznaczanie „do poprawy”.
 *
 * Oznaczenie jest stanem przeglądu, nie treścią: nie tworzy wersji i zostaje po zapisie klienta,
 * dopóki zespół go nie zdejmie. Komentarz widzi tylko zespół (MASTER_PLAN nie przewiduje widoku dla klienta).
 */
class ContentReviewService
{
	public const STATUSES = ['empty' => 'Puste', 'filled' => 'Wypełnione', 'review' => 'Do poprawy'];

	public const AUTHORS = ['klient' => 'Klient', 'zespol' => 'Zespół'];

	public function __construct(private ClientEditorService $editor, private ActivityLogger $activity)
	{
	}

	/**
	 * @param array{strona: ?int, status: string, autor: string} $filters
	 * @return array{items: Collection, counts: array<string, int>}
	 */
	public function list(Project $project, array $filters): array
	{
		$items = collect();

		foreach ($this->editor->pages($project) as $page) {
			if ($filters['strona'] && $filters['strona'] !== $page->id) {
				continue;
			}

			$structure = $this->editor->structure($page, true);

			foreach ($structure->sections as $section) {
				$this->walk($structure, $page, $section, null, null, [$section->name], $items);
			}
		}

		if ($filters['autor'] !== '') {
			$author = $filters['autor'] === 'klient' ? ActorType::Client : ActorType::User;
			$items = $items->filter(fn (array $item) => $item['author'] !== null && $item['value']->updated_by_type === $author);
		}

		$counts = array_map(fn () => 0, self::STATUSES);

		foreach ($items as $item) {
			$counts[$item['status']]++;
		}

		return [
			'items' => $filters['status'] !== '' ? $items->where('status', $filters['status'])->values() : $items->values(),
			'counts' => $counts,
		];
	}

	public function mark(Project $project, int $valueId, string $note, WP_User $by): void
	{
		$value = $this->value($project, $valueId);

		if (! ContentService::hasContent($value->value)) {
			throw ValidationException::withMessages(['note' => 'Puste pole nie ma treści do poprawy.']);
		}

		$value->forceFill(['review_note' => $note, 'review_requested_at' => now(), 'review_requested_by' => $by->ID])->save();

		$this->log('content.review_requested', $value, $by);
	}

	public function clear(Project $project, int $valueId, WP_User $by): void
	{
		$value = $this->value($project, $valueId);

		$value->forceFill(['review_note' => null, 'review_requested_at' => null, 'review_requested_by' => null])->save();

		$this->log('content.review_cleared', $value, $by);
	}

	private function value(Project $project, int $id): FieldValue
	{
		$value = FieldValue::query()->where('project_id', $project->id)->whereKey($id)->first();

		abort_unless($value !== null, 404);

		return $value;
	}

	private function log(string $event, FieldValue $value, WP_User $by): void
	{
		$field = Field::query()->findOrFail($value->field_id);

		$this->activity->log($event, Actor::user($by), $field->project, $field, [
			'page_name' => $field->section->page->name,
			'field_label' => $field->label,
		]);
	}

	/**
	 * @param string[] $path
	 */
	private function walk(ClientStructure $structure, Page $page, Section $section, ?Field $parent, ?RepeaterRow $row, array $path, Collection $items): void
	{
		foreach ($structure->children($section, $parent) as $field) {
			if ($field->type === FieldType::Group) {
				$this->walk($structure, $page, $section, $field, $row, [...$path, $field->label], $items);
			} elseif ($field->type === FieldType::Repeater) {
				foreach ($structure->rows($field, $row) as $index => $child) {
					$this->walk($structure, $page, $section, $field, $child, [...$path, $field->label . ' ' . ($index + 1)], $items);
				}
			} else {
				$items->push($this->item($structure, $page, $field, $row, $path));
			}
		}
	}

	private function item(ClientStructure $structure, Page $page, Field $field, ?RepeaterRow $row, array $path): array
	{
		$value = $structure->value($field, $row);
		$filled = ContentService::hasContent($value?->value);

		return [
			'page' => $page,
			'field' => $field,
			'path' => implode(' · ', $path),
			'value' => $value,
			'excerpt' => $filled ? $this->excerpt($field, $value->value) : null,
			'status' => match (true) {
				$value?->review_requested_at !== null => 'review',
				$filled => 'filled',
				default => 'empty',
			},
			'author' => $filled ? $this->author($value) : null,
		];
	}

	private function excerpt(Field $field, string $value): string
	{
		return $field->type->isMedia()
			? 'Zdjęcia: ' . count((array) json_decode($value, true))
			: Str::limit(ContentService::display($field, $value), 160);
	}

	public function author(FieldValue $value): string
	{
		return $value->updated_by_type === ActorType::Client
			? 'Klient'
			: (get_userdata((int) $value->updated_by_user_id)->display_name ?? 'Zespół');
	}
}
