<?php

namespace App\Services;

use App\Enums\FieldType;
use App\Models\Field;
use App\Models\Page;
use App\Models\Project;
use App\Models\Section;
use App\Models\WpMapping;
use App\Support\Actor;
use App\Support\ClientStructure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use WP_User;

/**
 * Mapowanie elementów projektu na WordPress i ACF. Domyślne wartości wynikają z typu i klucza pola,
 * a `fmi_wp_mappings` trzyma tylko nadpisania: podstrona → typ wpisu, slug i szablon, sekcja → blok
 * (`acf/<slug>`), pole → nazwa i typ ACF. Konwencja nazw: grupa `g_<klucz>`, repeater `r_<klucz>`.
 */
class AcfMappingService
{
	public const DEFAULT_POST_TYPE = 'page';

	public function __construct(private ClientEditorService $editor, private ActivityLogger $activity)
	{
	}

	/**
	 * Mapowanie typu pola. Brak gałęzi `default`: nowy FieldType musi dostać tu decyzję, także gdy to „nieobsługiwany”.
	 *
	 * @return array{supported: bool, acf_type: ?string, settings: array, alternatives: string[], message: ?string}
	 */
	public function definition(FieldType $type): array
	{
		$supported = fn (string $acf, array $settings = [], array $alternatives = []) => [
			'supported' => true,
			'acf_type' => $acf,
			'settings' => $settings,
			'alternatives' => [$acf, ...$alternatives],
			'message' => null,
		];

		return match ($type) {
			FieldType::Text => $supported('text', [], ['textarea', 'wysiwyg']),
			FieldType::Textarea => $supported('textarea', ['rows' => 4, 'new_lines' => ''], ['text', 'wysiwyg']),
			FieldType::Wysiwyg => $supported('wysiwyg', ['tabs' => 'all', 'toolbar' => 'full', 'media_upload' => 0], ['textarea']),
			FieldType::Link => $supported('link', ['return_format' => 'array']),
			FieldType::Image => $supported('image', ['return_format' => 'array', 'preview_size' => 'medium', 'library' => 'all']),
			FieldType::Gallery => $supported('gallery', ['return_format' => 'array', 'preview_size' => 'medium', 'library' => 'all']),
			FieldType::Group => $supported('group', ['layout' => 'block']),
			FieldType::Repeater => $supported('repeater', ['layout' => 'block', 'button_label' => 'Dodaj element']),
		};
	}

	/**
	 * Nazwy ACF rodzeństwa, unikalne w rodzicu. Nadpisania rezerwują nazwy przed domyślnymi.
	 *
	 * @param Collection<int, Field> $siblings
	 * @param Collection<int, WpMapping> $mappings mapowania pól wg `mappable_id`
	 * @return array<int, string>
	 */
	public function names(Collection $siblings, Collection $mappings): array
	{
		$taken = [];
		$names = [];

		foreach ($siblings as $field) {
			$override = $mappings[$field->id]->settings['acf_name'] ?? null;

			if ($override) {
				$names[$field->id] = $override;
				$taken[$override] = true;
			}
		}

		foreach ($siblings as $field) {
			if (isset($names[$field->id])) {
				continue;
			}

			$base = $this->defaultName($field);
			$name = $base;

			for ($i = 2; isset($taken[$name]); $i++) {
				$name = $base . '_' . $i;
			}

			$names[$field->id] = $name;
			$taken[$name] = true;
		}

		return $names;
	}

	public function defaultName(Field $field): string
	{
		$base = Str::slug((string) $field->name, '_') ?: 'pole';

		// ACF i PHP wymagają nazwy zaczynającej się od litery.
		if (ctype_digit($base[0])) {
			$base = 'pole_' . $base;
		}

		return match ($field->type) {
			FieldType::Group => str_starts_with($base, 'g_') ? $base : 'g_' . $base,
			FieldType::Repeater => str_starts_with($base, 'r_') ? $base : 'r_' . $base,
			default => $base,
		};
	}

	/**
	 * Mapowanie pola z nazwą już rozstrzygniętą przez `names()`. Stan `unmapped` niesie powód dla raportu braków.
	 *
	 * @return array{name: string, type: ?string, path: string, settings: array, mapped: bool, message: ?string}
	 */
	public function resolve(Field $field, string $name, ?string $parentPath, ?WpMapping $mapping): array
	{
		$definition = $this->definition($field->type);
		$override = $mapping?->settings['acf_type'] ?? null;
		$type = $override ?: $definition['acf_type'];
		$message = $definition['message'];

		if ($definition['supported'] && ! in_array($type, $definition['alternatives'], true)) {
			$message = sprintf('Typ ACF „%s” nie pasuje do typu pola „%s”.', $type, $field->type->label());
		}

		return [
			'name' => $name,
			'type' => $type,
			'path' => $parentPath === null ? $name : $parentPath . '.' . $name,
			'settings' => $definition['supported'] ? $this->settings($field, $definition) : [],
			'mapped' => $message === null,
			'message' => $message,
		];
	}

	/**
	 * Ścieżka dzieci kontenera: wiersze repeatera dokładają `*`.
	 */
	public function childPath(Field $field, string $path): string
	{
		return $field->type === FieldType::Repeater ? $path . '.*' : $path;
	}

	/**
	 * @return array{post_type: string, slug: string, template: ?string}
	 */
	public function pageMapping(Page $page, ?WpMapping $mapping): array
	{
		return [
			'post_type' => $mapping?->settings['post_type'] ?? self::DEFAULT_POST_TYPE,
			'slug' => $mapping?->settings['slug'] ?? $page->slug,
			'template' => $mapping?->settings['template'] ?? null,
		];
	}

	/**
	 * Bloki sekcji podstrony: domyślnie z nazwy sekcji, unikalne na podstronie.
	 *
	 * @param Collection<int, Section> $sections
	 * @param Collection<int, WpMapping> $mappings mapowania sekcji wg `mappable_id`
	 * @return array<int, string>
	 */
	public function blocks(Collection $sections, Collection $mappings): array
	{
		$blocks = [];
		$taken = [];

		foreach ($sections as $section) {
			$block = $mappings[$section->id]->acf_block ?? null;

			if (! $block) {
				$base = 'acf/' . (Str::slug($section->name) ?: 'sekcja');
				$block = $base;

				for ($i = 2; isset($taken[$block]); $i++) {
					$block = $base . '-' . $i;
				}
			}

			$blocks[$section->id] = $block;
			$taken[$block] = true;
		}

		return $blocks;
	}

	/**
	 * @return Collection<int, WpMapping>
	 */
	public function mappings(Project $project, string $type): Collection
	{
		return WpMapping::query()->where('project_id', $project->id)->where('mappable_type', $type)->get()->keyBy('mappable_id');
	}

	/**
	 * Dane formularza mapowania: płaskie drzewo pól podstrony.
	 */
	public function form(Page $page): array
	{
		$project = $page->project;
		$structure = $this->editor->structure($page, true);
		$fieldMappings = $this->mappings($project, (new Field)->getMorphClass());
		$sectionMappings = $this->mappings($project, (new Section)->getMorphClass());
		$blocks = $this->blocks($structure->sections, $sectionMappings);
		$sections = [];

		foreach ($structure->sections as $section) {
			$rows = [];
			$this->collect($structure, $section, null, null, 0, $fieldMappings, $rows);
			$sections[] = ['section' => $section, 'block' => $blocks[$section->id], 'override' => $sectionMappings[$section->id]->acf_block ?? null, 'fields' => $rows];
		}

		return [
			'page' => $this->pageMapping($page, $page->mapping),
			'sections' => $sections,
		];
	}

	/**
	 * @param array{page: array, sections?: array, fields?: array} $data
	 */
	public function save(Page $page, array $data, WP_User $by): void
	{
		$project = $page->project;
		$structure = $this->editor->structure($page, true);
		$fieldMorph = (new Field)->getMorphClass();
		$sectionMorph = (new Section)->getMorphClass();
		$overrides = $this->mappings($project, $fieldMorph);
		$fields = [];

		foreach ($structure->sections as $section) {
			$this->flatten($structure, $section, null, $fields);
		}

		$known = collect($fields)->keyBy('id');
		$input = (array) ($data['fields'] ?? []);

		if (array_diff(array_keys($input), $known->keys()->all())) {
			throw ValidationException::withMessages(['fields' => 'Formularz zawiera pola spoza tej podstrony.']);
		}

		$sectionIds = $structure->sections->modelKeys();

		if (array_diff(array_keys((array) ($data['sections'] ?? [])), $sectionIds)) {
			throw ValidationException::withMessages(['sections' => 'Formularz zawiera sekcje spoza tej podstrony.']);
		}

		foreach ($input as $id => $values) {
			$type = $values['type'] ?? null;

			if ($type && ! in_array($type, $this->definition($known[$id]->type)['alternatives'], true)) {
				throw ValidationException::withMessages(["fields.{$id}.type" => sprintf('Typ ACF „%s” nie pasuje do pola „%s”.', $type, $known[$id]->label)]);
			}
		}

		$this->assertUniqueNames($known, $input, $overrides);

		DB::transaction(function () use ($page, $project, $data, $input, $known, $fieldMorph, $sectionMorph) {
			$this->store($project, (new Page)->getMorphClass(), $page->id, ['settings' => [
				'post_type' => $data['page']['post_type'],
				'slug' => ($data['page']['slug'] ?? null) ?: null,
				'template' => $data['page']['template'] ?? null,
			]]);

			foreach ((array) ($data['sections'] ?? []) as $id => $values) {
				$this->store($project, $sectionMorph, (int) $id, ['acf_block' => ($values['block'] ?? null) ?: null]);
			}

			foreach ($input as $id => $values) {
				$settings = array_filter(['acf_name' => $values['name'] ?? null, 'acf_type' => $values['type'] ?? null]);

				// Typ domyślny nie jest nadpisaniem: po zmianie domyślnego mapowania pole pójdzie za nim.
				if (($settings['acf_type'] ?? null) === $this->definition($known[$id]->type)['acf_type']) {
					unset($settings['acf_type']);
				}

				$this->store($project, $fieldMorph, (int) $id, ['settings' => $settings ?: null]);
			}
		});

		$this->activity->log('mapping.updated', Actor::user($by), $project, $page, ['page_name' => $page->name]);
	}

	private function store(Project $project, string $type, int $id, array $attributes): void
	{
		WpMapping::query()->updateOrCreate(['mappable_type' => $type, 'mappable_id' => $id], $attributes + ['project_id' => $project->id]);
	}

	/**
	 * Porównanie po stanie docelowym: pola bez wpisu w formularzu zachowują zapisaną nazwę.
	 *
	 * @param Collection<int, Field> $known
	 * @param Collection<int, WpMapping> $overrides
	 */
	private function assertUniqueNames(Collection $known, array $input, Collection $overrides): void
	{
		$seen = [];

		foreach ($known as $id => $field) {
			$name = array_key_exists($id, $input) ? ($input[$id]['name'] ?? null) : ($overrides[$id]->settings['acf_name'] ?? null);

			if (! $name) {
				continue;
			}

			$scope = $field->section_id . '|' . (int) $field->parent_id . '|' . $name;

			if (isset($seen[$scope])) {
				throw ValidationException::withMessages(["fields.{$id}.name" => sprintf('Nazwa „%s” powtarza się w tym samym rodzicu.', $name)]);
			}

			$seen[$scope] = true;
		}
	}

	private function settings(Field $field, array $definition): array
	{
		$own = (array) $field->settings;
		$limits = array_filter([
			'maxlength' => $own['max_length'] ?? null,
			'placeholder' => $own['placeholder'] ?? null,
			'min' => $own['min_items'] ?? null,
			'max' => $own['max_items'] ?? null,
			'min_width' => $own['min_width'] ?? null,
			'min_height' => $own['min_height'] ?? null,
		], fn ($value) => $value !== null && $value !== '');

		return $definition['settings'] + $limits;
	}

	private function collect(ClientStructure $structure, Section $section, ?Field $parent, ?string $path, int $depth, Collection $mappings, array &$rows): void
	{
		$children = $structure->children($section, $parent);
		$names = $this->names($children, $mappings);

		foreach ($children as $field) {
			$resolved = $this->resolve($field, $names[$field->id], $path, $mappings[$field->id] ?? null);
			$rows[] = [
				'field' => $field,
				'depth' => $depth,
				'acf' => $resolved,
				'default_name' => $this->defaultName($field),
				'name_override' => $mappings[$field->id]->settings['acf_name'] ?? null,
				'type_override' => $mappings[$field->id]->settings['acf_type'] ?? null,
				'alternatives' => $this->definition($field->type)['alternatives'],
			];

			if ($field->type->isContainer()) {
				$this->collect($structure, $section, $field, $this->childPath($field, $resolved['path']), $depth + 1, $mappings, $rows);
			}
		}
	}

	/**
	 * @param Field[] $fields
	 */
	private function flatten(ClientStructure $structure, Section $section, ?Field $parent, array &$fields): void
	{
		foreach ($structure->children($section, $parent) as $field) {
			$fields[] = $field;

			if ($field->type->isContainer()) {
				$this->flatten($structure, $section, $field, $fields);
			}
		}
	}
}
