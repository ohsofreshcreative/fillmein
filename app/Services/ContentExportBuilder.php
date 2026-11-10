<?php

namespace App\Services;

use App\Enums\FieldType;
use App\Models\Asset;
use App\Models\Field;
use App\Models\FieldRevision;
use App\Models\FieldValue;
use App\Models\Page;
use App\Models\Project;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Models\SectionNote;
use App\Support\ClientStructure;
use Illuminate\Support\Str;

/**
 * Uniwersalny eksport treści `content.json` (format `fmi-content`, opis: `docs/export-format.md`).
 *
 * Drzewo projekt → podstrony → sekcje → pola, bez nazw bloków i pól ACF — mapowanie na WordPressa zostaje
 * po stronie przyszłej wtyczki. Każdy klucz jest budowany jawnie: bez autorów, e-maili, tokenów, ścieżek dysku
 * i adresów Figmy (tylko klucz pliku i ID warstw).
 *
 * Stan pola: `filled` (ma treść), `cleared` (kiedyś miało treść, ktoś ją usunął — widać to w rewizjach),
 * `empty` (nigdy nie uzupełnione).
 */
class ContentExportBuilder
{
	/** Zmiana niezgodna wstecz podbija część główną; nowy opcjonalny klucz — poboczną i wpis w schemacie. */
	public const SCHEMA_VERSION = '2.0';

	public const FORMAT = 'fmi-content';

	private array $media = [];

	private array $missing = [];

	private array $counts = [];

	private array $fieldIds = [];

	/** @var array<int, true> ID wartości, które w historii miały treść */
	private array $hadContent = [];

	public function __construct(private ClientEditorService $editor)
	{
	}

	/**
	 * @param Page[] $pages podstrony w kolejności drzewa (ExportService::pagesFor)
	 */
	public function build(Project $project, array $pages, bool $whole = true, ?array $history = null): array
	{
		$this->media = [];
		$this->missing = [];
		$this->fieldIds = [];
		$this->counts = ['pages' => 0, 'sections' => 0, 'fields' => 0, 'filled' => 0, 'empty' => 0, 'cleared' => 0, 'media' => 0, 'media_links' => 0, 'missing' => 0];
		$this->hadContent = $history ?? $this->valuesWithHistory($project);

		$included = [];

		foreach ($pages as $page) {
			$included[$page->id] = $page->uuid;
		}

		$tree = [];

		foreach ($pages as $page) {
			$tree[] = $this->page($page, $included);
		}

		ksort($this->media);

		$this->counts['pages'] = count($tree);
		$this->counts['fields'] = count($this->fieldIds);
		$this->counts['media'] = count($this->media);
		$this->counts['missing'] = count($this->missing);

		return [
			'format' => self::FORMAT,
			'schema_version' => self::SCHEMA_VERSION,
			'generator' => ['name' => 'fill-me-in'],
			'exported_at' => now()->toIso8601ZuluString(),
			'scope' => ['type' => $whole ? 'project' : 'pages', 'page_ids' => array_values($included)],
			'project' => [
				'id' => $project->uuid,
				'name' => $project->name,
				'slug' => Str::slug($project->name) ?: 'projekt',
				'figma' => $project->figma_file_key ? ['file_key' => (string) $project->figma_file_key] : null,
			],
			'summary' => $this->counts,
			'pages' => $tree,
			'media' => array_values($this->media),
			'missing' => $this->missing,
		];
	}

	/**
	 * Liczby do podglądu przed eksportem, osobno dla każdej podstrony (UI sumuje wybrane).
	 *
	 * @param Page[] $pages
	 * @return array<string, array> klucz: UUID podstrony
	 */
	public function summaries(Project $project, array $pages): array
	{
		$result = [];
		$history = $this->valuesWithHistory($project);

		foreach ($pages as $page) {
			$result[$page->uuid] = $this->build($project, [$page], false, $history)['summary'];
		}

		return $result;
	}

	private function page(Page $page, array $included): array
	{
		$structure = $this->editor->structure($page, true);
		$notes = SectionNote::query()->whereIn('section_id', $structure->sections->modelKeys())->pluck('body', 'section_id');
		$sections = [];

		foreach ($structure->sections as $section) {
			$this->counts['sections']++;
			$note = $notes[$section->id] ?? null;

			$sections[] = [
				'id' => $section->uuid,
				'name' => $section->name,
				'position' => (int) $section->position,
				'hidden' => (bool) $section->is_hidden,
				'figma' => $this->node($section->figma_node_id, $section->figma_node_name),
				'client_note' => filled($note) ? (string) $note : null,
				'fields' => $this->fields($structure, $section, null, null, ['page' => $page, 'section' => $section]),
			];
		}

		return [
			'id' => $page->uuid,
			// Rodzic spoza eksportu (archiwum albo niewybrany) — podstrona staje się korzeniem.
			'parent_id' => $included[$page->parent_id] ?? null,
			'name' => $page->name,
			'slug' => (string) $page->slug,
			'position' => (int) $page->position,
			'figma' => $this->node($page->figma_node_id, $page->figma_node_name),
			'sections' => $sections,
		];
	}

	private function fields(ClientStructure $structure, Section $section, ?Field $parent, ?RepeaterRow $row, array $where): array
	{
		$result = [];

		foreach ($structure->children($section, $parent) as $field) {
			$result[] = $this->field($structure, $section, $field, $row, $where);
		}

		return $result;
	}

	private function field(ClientStructure $structure, Section $section, Field $field, ?RepeaterRow $row, array $where): array
	{
		$this->fieldIds[$field->id] = true;

		$entry = [
			'id' => $field->uuid,
			'key' => (string) $field->name,
			'label' => (string) $field->label,
			'type' => $field->type->value,
			'required' => (bool) $field->is_required,
			'hidden' => (bool) $field->is_hidden,
			'instructions' => $field->instructions,
			'position' => (int) $field->position,
			'settings' => (object) ((array) $field->settings),
			'figma' => $field->figma_node_id ? ['node_id' => (string) $field->figma_node_id, 'text' => $field->figma_text] : null,
		];

		$stored = $structure->value($field, $row);

		if ($field->type === FieldType::Group) {
			// Grupa nie ma własnej wartości: stan wynika z pól w środku.
			$children = $this->fields($structure, $section, $field, $row, $where);
			$states = array_column($children, 'state');

			return $entry + [
				'state' => in_array('filled', $states, true) ? 'filled' : (in_array('cleared', $states, true) ? 'cleared' : 'empty'),
				'fields' => $children,
			];
		}

		if ($field->type === FieldType::Repeater) {
			$rows = [];

			foreach ($structure->rows($field, $row)->values() as $index => $child) {
				$rows[] = ['id' => $child->uuid, 'position' => $index, 'fields' => $this->fields($structure, $section, $field, $child, $where)];
			}

			$this->checkCount($field, count($rows), $row, $where);

			return $entry + ['state' => $this->state($rows !== [], $stored), 'rows' => $rows];
		}

		if ($field->type->isMedia()) {
			$refs = $structure->assets($field, $row)->map(function (Asset $asset) {
				$item = $this->mediaEntry($asset);
				$this->media[$asset->uuid] = $item;

				return ['media_id' => $item['id'], 'path' => $item['path']];
			})->values()->all();

			$link = ContentService::mediaLink($stored);

			if ($link) {
				$this->counts['media_links']++;
			}

			$this->checkCount($field, count($refs) + ($link ? 1 : 0), $row, $where);

			if (! empty($field->settings['require_alt']) && $structure->assets($field, $row)->contains(fn (Asset $asset) => blank($asset->alt))) {
				$this->miss($where, $field, $row, 'missing_alt');
			}

			return $entry + [
				'state' => $this->state($refs !== [] || $link !== null, $stored),
				'value' => $field->type === FieldType::Image ? ($refs[0] ?? null) : $refs,
				'source_link' => $link,
			];
		}

		$filled = ContentService::hasContent($stored?->value);

		if ($field->is_required && ! $filled) {
			$this->miss($where, $field, $row, 'empty');
		}

		$value = match (true) {
			! $filled => null,
			$field->type === FieldType::Link => $this->link((string) $stored->value),
			default => (string) $stored->value,
		};

		return $entry + ['state' => $this->state($filled, $stored), 'value' => $value];
	}

	private function link(string $json): array
	{
		$decoded = json_decode($json, true);

		return [
			'label' => is_array($decoded) ? (string) ($decoded['label'] ?? '') : '',
			'url' => is_array($decoded) ? (string) ($decoded['url'] ?? '') : '',
		];
	}

	private function state(bool $filled, ?FieldValue $stored): string
	{
		$state = match (true) {
			$filled => 'filled',
			$stored !== null && isset($this->hadContent[$stored->id]) => 'cleared',
			default => 'empty',
		};

		$this->counts[$state]++;

		return $state;
	}

	/**
	 * Jedno zapytanie na projekt: wartości, których któraś rewizja miała treść.
	 */
	private function valuesWithHistory(Project $project): array
	{
		return FieldRevision::query()
			->where('project_id', $project->id)
			->whereNotNull('value')
			->whereNotIn('value', ['', '[]'])
			->distinct()
			->pluck('field_value_id')
			->mapWithKeys(fn ($id) => [(int) $id => true])
			->all();
	}

	private function checkCount(Field $field, int $count, ?RepeaterRow $row, array $where): void
	{
		$minimum = (int) ($field->settings['min_items'] ?? 0);

		if ($count === 0 && $field->is_required) {
			$this->miss($where, $field, $row, 'empty');
		} elseif ($count < $minimum) {
			$this->miss($where, $field, $row, 'too_few');
		}
	}

	private function miss(array $where, Field $field, ?RepeaterRow $row, string $reason): void
	{
		$this->missing[] = array_filter([
			'page_id' => $where['page']->uuid,
			'page' => $where['page']->name,
			'section_id' => $where['section']->uuid,
			'section' => $where['section']->name,
			'field_id' => $field->uuid,
			'field' => (string) $field->label,
			'row_id' => $row?->uuid,
			'reason' => $reason,
		], fn ($value) => $value !== null);
	}

	private function node(?string $id, ?string $name): ?array
	{
		return $id ? ['node_id' => $id, 'node_name' => $name] : null;
	}

	public static function mediaPath(Asset $asset): string
	{
		return sprintf('media/%s.%s', $asset->checksum, $asset->extension);
	}

	private function mediaEntry(Asset $asset): array
	{
		return [
			'id' => $asset->uuid,
			'path' => self::mediaPath($asset),
			'file_name' => (string) $asset->original_name,
			'mime' => $asset->mime_type,
			'size' => (int) $asset->size,
			'width' => $asset->width === null ? null : (int) $asset->width,
			'height' => $asset->height === null ? null : (int) $asset->height,
			'sha256' => $asset->checksum,
			'alt' => $asset->alt,
			'caption' => $asset->caption,
		];
	}
}
