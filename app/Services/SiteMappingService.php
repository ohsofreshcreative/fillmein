<?php

namespace App\Services;

use App\Enums\FieldType;
use App\Models\Field;
use App\Models\FieldValue;
use App\Models\Page;
use App\Models\Project;
use App\Models\Section;
use App\Models\SiteMapping;
use App\Models\SiteSchema;
use App\Support\Actor;
use App\Support\ClientStructure;
use App\Support\SiteSchemaIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use WP_User;

/**
 * Schemat strony WordPress w projekcie i dopasowanie do niego podstron, sekcji i pól.
 *
 * - Wgranie schematu zastępuje poprzedni, ręczne dopasowania zostają (wskazujące na nieistniejący blok lub pole
 *   są oznaczane jako nieaktualne), a automat liczy się od nowa.
 * - Automat (SiteMatcher) nigdy nie nadpisuje dopasowań `manual`. Po każdej zmianie ręcznej liczy się ponownie,
 *   żeby pola sekcji szły za wybranym blokiem.
 * - resolve() daje końcowe mapowanie dla ekranu przeglądu i eksportu (ścieżki ACF, wystąpienia bloków, braki).
 */
class SiteMappingService
{
	public const FORMAT = 'fmi-site-schema';

	public const MAX_BYTES = 10 * 1024 * 1024;

	public function __construct(
		private ClientEditorService $editor,
		private SiteMatcher $matcher,
		private AcfMappingService $acf,
		private ActivityLogger $activity,
	) {
	}

	public function schema(Project $project): ?SiteSchema
	{
		return SiteSchema::query()->where('project_id', $project->id)->first();
	}

	public function index(Project $project): ?SiteSchemaIndex
	{
		$schema = $this->schema($project);

		return $schema ? new SiteSchemaIndex((array) $schema->document) : null;
	}

	/* ---------------------------------------------------------------------
	 * Wgrywanie
	 * ------------------------------------------------------------------- */

	/**
	 * @throws ValidationException z czytelnym komunikatem pod kluczem `schema`
	 */
	public function parse(string $json): array
	{
		$fail = fn (string $message) => throw ValidationException::withMessages(['schema' => $message]);

		if (strlen($json) > self::MAX_BYTES) {
			$fail('Plik schematu jest za duży (limit 10 MB).');
		}

		$document = json_decode($json, true);

		if (! is_array($document)) {
			$fail('Plik nie jest poprawnym JSON-em.');
		}

		if (($document['format'] ?? null) !== self::FORMAT) {
			$fail(sprintf('To nie jest schemat strony z wtyczki Fill Me In Sync (oczekiwano "format": "%s"). Wygeneruj go poleceniem „wp fmi-sync schema”.', self::FORMAT));
		}

		if (! is_string($document['schema_version'] ?? null) || ! preg_match('/^1\.\d+$/', $document['schema_version'])) {
			$fail('Nieobsługiwana wersja schematu — Fill Me In obsługuje wersje 1.x.');
		}

		if (! is_string($document['site']['url'] ?? null) || $document['site']['url'] === '') {
			$fail('W schemacie brakuje adresu strony (site.url).');
		}

		if (! is_array($document['blocks'] ?? null) || ! is_array($document['pages'] ?? null)) {
			$fail('W schemacie brakuje list bloków (blocks) lub stron (pages).');
		}

		foreach ($document['blocks'] as $block) {
			if (! is_array($block) || ! is_string($block['name'] ?? null) || ! is_array($block['fields'] ?? [])) {
				$fail('Schemat zawiera niepoprawny opis bloku.');
			}
		}

		foreach ($document['pages'] as $page) {
			if (! is_array($page) || ! isset($page['id']) || ! is_array($page['blocks'] ?? [])) {
				$fail('Schemat zawiera niepoprawny opis strony.');
			}
		}

		return $document;
	}

	public function upload(Project $project, string $json, WP_User $by): SiteSchema
	{
		$document = $this->parse($json);

		$schema = DB::transaction(function () use ($project, $document, $by) {
			$schema = SiteSchema::query()->updateOrCreate(['project_id' => $project->id], [
				'site_url' => Str::limit((string) $document['site']['url'], 490, ''),
				'site_name' => isset($document['site']['name']) ? Str::limit((string) $document['site']['name'], 180, '') : null,
				'schema_version' => (string) $document['schema_version'],
				'document' => $document,
				'uploaded_by' => $by->ID,
				'uploaded_at' => now(),
			]);

			$this->autoMatch($project);

			return $schema;
		});

		$this->activity->log('site_schema.uploaded', Actor::user($by), $project, null, [
			'site_url' => $schema->site_url,
			'blocks' => count($document['blocks']),
			'pages' => count($document['pages']),
		]);

		return $schema;
	}

	/* ---------------------------------------------------------------------
	 * Dopasowanie
	 * ------------------------------------------------------------------- */

	/**
	 * Przelicza wszystkie dopasowania `auto` projektu. Ręczne zostają bez zmian.
	 */
	public function autoMatch(Project $project, ?WP_User $by = null): void
	{
		$index = $this->index($project);

		if (! $index) {
			return;
		}

		$manual = SiteMapping::query()->where('project_id', $project->id)->where('source', 'manual')->get();
		$fixed = ['pages' => [], 'sections' => [], 'fields' => []];

		foreach ($manual as $mapping) {
			match ($mapping->mappable_type) {
				SiteMapping::PAGE => $fixed['pages'][$mapping->mappable_id] = $mapping->target,
				SiteMapping::SECTION => $fixed['sections'][$mapping->mappable_id] = $mapping->target === null ? null : ['block' => $mapping->target, 'position' => $mapping->position],
				SiteMapping::FIELD => $fixed['fields'][$mapping->mappable_id] = $mapping->target,
				default => null,
			};
		}

		$result = $this->matcher->match($this->input($project), $index, $fixed);
		$now = now();
		$rows = [];
		$row = fn (string $type, int $id, ?string $target, ?int $position, float $confidence) => [
			'project_id' => $project->id,
			'mappable_type' => $type,
			'mappable_id' => $id,
			'target' => $target,
			'position' => $position,
			'confidence' => round($confidence, 3),
			'source' => 'auto',
			'created_at' => $now,
			'updated_at' => $now,
		];

		foreach ($result['pages'] as $id => $item) {
			$rows[] = $row(SiteMapping::PAGE, $id, $item['target'], null, $item['confidence']);
		}

		foreach ($result['sections'] as $id => $item) {
			$rows[] = $row(SiteMapping::SECTION, $id, $item['block'], $item['position'], $item['confidence']);
		}

		foreach ($result['fields'] as $id => $item) {
			$rows[] = $row(SiteMapping::FIELD, $id, $item['key'], null, $item['confidence']);
		}

		DB::transaction(function () use ($project, $rows) {
			SiteMapping::query()->where('project_id', $project->id)->where('source', 'auto')->delete();

			foreach (array_chunk($rows, 500) as $chunk) {
				SiteMapping::query()->insert($chunk);
			}
		});

		if ($by) {
			$this->activity->log('site_mapping.auto', Actor::user($by), $project, null, []);
		}
	}

	/**
	 * Zmiany z ekranu przeglądu. Ręczne staje się tylko to, co pracownik zmienił względem stanu z formularza
	 * (`original`), więc zapis strony nie zamraża reszty propozycji automatu.
	 *
	 * @param array{page?: ?string, original_page?: ?string, sections?: array, original_sections?: array, fields?: array, original_fields?: array} $input
	 */
	public function saveManual(Project $project, Page $page, array $input, WP_User $by): int
	{
		$index = $this->index($project) ?? throw ValidationException::withMessages(['schema' => 'Najpierw wgraj schemat strony.']);
		$structure = $this->editor->structure($page, true);
		$sections = $structure->sections->keyBy('id');
		$fields = [];

		foreach ($structure->sections as $section) {
			$this->flatten($structure, $section, null, $fields);
		}

		$changes = [];
		$changed = fn (string $key, $id = null) => (string) ($id === null ? ($input[$key] ?? '') : ($input[$key][$id] ?? '')) !== (string) ($id === null ? ($input['original_' . $key] ?? '') : ($input['original_' . $key][$id] ?? ''));

		if (array_key_exists('page', $input) && $changed('page')) {
			$target = (string) ($input['page'] ?? '');

			if ($target !== '' && ! $index->page($target)) {
				throw ValidationException::withMessages(['page' => 'Wybrana strona nie istnieje w schemacie.']);
			}

			$changes[] = [SiteMapping::PAGE, $page->id, $target === '' ? null : $target, null];
		}

		$movedSections = [];

		foreach ((array) ($input['sections'] ?? []) as $id => $value) {
			if (! $sections->has((int) $id)) {
				throw ValidationException::withMessages(['sections' => 'Formularz zawiera sekcje spoza tej podstrony.']);
			}

			if (! $changed('sections', $id)) {
				continue;
			}

			[$block, $position] = array_pad(explode('|', (string) $value, 2), 2, '');

			if ($block !== '' && ! $index->block($block)) {
				throw ValidationException::withMessages(["sections.{$id}" => 'Wybrany blok nie istnieje w schemacie.']);
			}

			$movedSections[(int) $id] = true;
			$changes[] = [SiteMapping::SECTION, (int) $id, $block === '' ? null : $block, $block !== '' && $position !== '' ? (int) $position : null];
		}

		$known = collect($fields)->keyBy('id');

		foreach ((array) ($input['fields'] ?? []) as $id => $key) {
			$field = $known[(int) $id] ?? throw ValidationException::withMessages(['fields' => 'Formularz zawiera pola spoza tej podstrony.']);

			// Lista pól pochodzi z poprzedniego bloku sekcji — po zmianie bloku decyduje automat.
			if (isset($movedSections[$field->section_id]) || ! $changed('fields', $id)) {
				continue;
			}

			$key = (string) $key;

			if ($key !== '') {
				$target = $this->findField($index, $key);

				if (! $target || SiteMatcher::compatibility($field->type->value, $target['type']) === null) {
					throw ValidationException::withMessages(["fields.{$id}" => sprintf('Pole ACF nie pasuje do pola „%s”.', $field->label)]);
				}
			}

			$changes[] = [SiteMapping::FIELD, (int) $id, $key === '' ? null : $key, null];
		}

		if ($changes === []) {
			return 0;
		}

		DB::transaction(function () use ($project, $changes) {
			foreach ($changes as [$type, $id, $target, $position]) {
				SiteMapping::query()->updateOrCreate(['mappable_type' => $type, 'mappable_id' => $id], [
					'project_id' => $project->id,
					'target' => $target,
					'position' => $position,
					'confidence' => 1,
					'source' => 'manual',
				]);
			}

			$this->autoMatch($project);
		});

		$this->activity->log('site_mapping.updated', Actor::user($by), $project, $page, ['page_name' => $page->name, 'changes' => count($changes)]);

		return count($changes);
	}

	/**
	 * „Przywróć automat” dla podstrony, sekcji (z jej polami) albo pola.
	 */
	public function restore(Project $project, Page $page, string $type, int $id, WP_User $by): void
	{
		$structure = $this->editor->structure($page, true);
		$ids = [];

		if ($type === SiteMapping::PAGE && $id === $page->id) {
			$ids[SiteMapping::PAGE] = [$page->id];
		} elseif ($type === SiteMapping::SECTION && ($section = $structure->sections->firstWhere('id', $id))) {
			$fields = [];
			$this->flatten($structure, $section, null, $fields);
			$ids[SiteMapping::SECTION] = [$section->id];
			$ids[SiteMapping::FIELD] = array_map(fn (Field $field) => $field->id, $fields);
		} elseif ($type === SiteMapping::FIELD) {
			foreach ($structure->sections as $section) {
				$fields = [];
				$this->flatten($structure, $section, null, $fields);

				foreach ($fields as $field) {
					if ($field->id === $id) {
						$ids[SiteMapping::FIELD] = [$id];
					}
				}
			}
		}

		if ($ids === []) {
			throw ValidationException::withMessages(['restore' => 'Ten element nie należy do podstrony.']);
		}

		DB::transaction(function () use ($project, $ids) {
			foreach ($ids as $kind => $list) {
				SiteMapping::query()->where('project_id', $project->id)->where('mappable_type', $kind)->whereIn('mappable_id', $list)->where('source', 'manual')->delete();
			}

			$this->autoMatch($project);
		});

		$this->activity->log('site_mapping.updated', Actor::user($by), $project, $page, ['page_name' => $page->name, 'restored' => $type]);
	}

	/* ---------------------------------------------------------------------
	 * Końcowe mapowanie
	 * ------------------------------------------------------------------- */

	/**
	 * Mapowanie podstron, sekcji i pól wg zapisanych dopasowań i aktualnego schematu.
	 *
	 * @param Page[]|null $pages domyślnie wszystkie aktywne podstrony
	 * @return array{index: SiteSchemaIndex, schema: SiteSchema, pages: array, sections: array, fields: array, warnings: array}|null
	 */
	public function resolve(Project $project, ?array $pages = null): ?array
	{
		$schema = $this->schema($project);

		if (! $schema) {
			return null;
		}

		$index = new SiteSchemaIndex((array) $schema->document);
		$stored = SiteMapping::query()->where('project_id', $project->id)->get()->groupBy('mappable_type')->map(fn ($group) => $group->keyBy('mappable_id'));
		$samples = $this->samples($project);
		$result = ['index' => $index, 'schema' => $schema, 'pages' => [], 'sections' => [], 'fields' => [], 'warnings' => ['unmapped' => [], 'required' => [], 'stale' => []]];

		foreach ($pages ?? $this->editor->pages($project)->all() as $page) {
			$mapping = $stored[SiteMapping::PAGE][$page->id] ?? null;
			$target = $mapping?->target;
			$schemaPage = $index->page($target);
			$stale = $mapping?->isManual() && $target !== null && ! $schemaPage;
			$fallback = $this->acf->pageMapping($page, $page->mapping);

			$result['pages'][$page->id] = [
				'target' => $schemaPage ? $target : null,
				'schema_page' => $schemaPage,
				'confidence' => (float) ($mapping?->confidence ?? 0),
				'source' => $mapping?->source ?? 'auto',
				'stale' => $stale,
				// Strona bez pary to nowa strona — wtyczka utworzy ją przy imporcie.
				'status' => $this->status($schemaPage !== null, $mapping, $stale, 'new'),
				'mapping' => $schemaPage
					? ['post_type' => (string) ($schemaPage['post_type'] ?? 'page'), 'slug' => (string) ($schemaPage['slug'] ?? $page->slug), 'template' => ($schemaPage['template'] ?? null) ?: null]
					: $fallback,
			];

			if ($stale) {
				$result['warnings']['stale'][] = ['page' => $page, 'what' => 'Podstrona', 'label' => $page->name];
			}

			$structure = $this->editor->structure($page, true);
			$seen = [];

			foreach ($structure->sections as $section) {
				$mapping = $stored[SiteMapping::SECTION][$section->id] ?? null;
				$block = $mapping?->target;
				$valid = $index->block($block) !== null;
				$stale = $mapping?->isManual() && $block !== null && ! $valid;
				$block = $valid ? $block : null;
				$at = $index->pageBlockAt($result['pages'][$page->id]['target'], $mapping?->position);

				$result['sections'][$section->id] = [
					'page_id' => $page->id,
					'target' => $mapping?->target,
					'block' => $block,
					'position' => $at && $at['name'] === $block ? $at['position'] : null,
					// Wtyczka łączy n-tą widoczną sekcję z blokiem X z n-tym blokiem X na stronie.
					'occurrence' => $block && ! $section->is_hidden ? ($seen[$block] = ($seen[$block] ?? -1) + 1) : null,
					'confidence' => (float) ($mapping?->confidence ?? 0),
					'source' => $mapping?->source ?? 'auto',
					'stale' => $stale,
					'hidden' => (bool) $section->is_hidden,
					'status' => $section->is_hidden ? 'hidden' : $this->status($block !== null, $mapping, $stale),
				];

				if ($stale) {
					$result['warnings']['stale'][] = ['page' => $page, 'what' => 'Sekcja', 'label' => $section->name];
				}

				$this->resolveFields($structure, $section, null, null, $block, $index, $stored[SiteMapping::FIELD] ?? collect(), $samples, $page, $result, 0);

				if ($block && ! $section->is_hidden) {
					$this->requiredWarnings($index, $page, $section, $block, $structure, $result);
				}
			}
		}

		return $result;
	}

	private function resolveFields(ClientStructure $structure, Section $section, ?Field $parent, ?string $scope, ?string $block, SiteSchemaIndex $index, $stored, array $samples, Page $page, array &$result, int $depth): void
	{
		foreach ($structure->children($section, $parent) as $field) {
			$mapping = $stored[$field->id] ?? null;
			$key = $mapping?->target;
			$acf = $index->field($block, $key);
			$valid = $acf !== null
				&& $acf['scope'] === $scope
				&& SiteMatcher::compatibility($field->type->value, $acf['type']) !== null;
			$stale = $mapping?->isManual() && $key !== null && ! $valid;
			$skipped = $mapping?->isManual() && $key === null;
			$group = $field->type === FieldType::Group;
			$sample = $samples[$field->id] ?? null;

			$entry = [
				'field' => $field,
				'depth' => $depth,
				'page_id' => $page->id,
				'target' => $key,
				'key' => $valid ? $key : null,
				'acf' => $valid ? $acf : null,
				'confidence' => (float) ($mapping?->confidence ?? 0),
				'source' => $mapping?->source ?? 'auto',
				'stale' => $stale,
				'skipped' => $skipped,
				// Grupa z Figmy bez odpowiednika w ACF — w ścieżkach jej nie ma, pola w środku mają własne pary.
				'transparent' => $group && ! $valid && ! $stale,
				'scope' => $scope,
				'section_id' => $section->id,
				'sample' => $sample,
				'has_content' => $sample !== null,
			];
			$entry['status'] = match (true) {
				$section->is_hidden => 'hidden',
				$entry['transparent'] => 'transparent',
				$skipped => 'skipped',
				default => $this->status($valid, $mapping, $stale),
			};

			$result['fields'][$field->id] = $entry;

			if ($stale) {
				$result['warnings']['stale'][] = ['page' => $page, 'what' => 'Pole', 'label' => $section->name . ' › ' . $field->label];
			}

			if (! $group && ! $valid && ! $skipped && $sample !== null && ! $section->is_hidden) {
				$result['warnings']['unmapped'][] = ['page' => $page, 'label' => $section->name . ' › ' . $field->label];
			}

			if ($field->type->isContainer()) {
				// Repeater bez pary: jego dzieci nie mają zakresu, w którym mogłyby się zmieścić.
				$childScope = $field->type === FieldType::Repeater ? ($valid ? $key : '__none__') : $scope;
				$this->resolveFields($structure, $section, $field, $childScope, $block, $index, $stored, $samples, $page, $result, $depth + 1);
			}
		}
	}

	/**
	 * Wymagane pola ACF bez źródła: w korzeniu bloku i w repeaterach, które mają parę.
	 */
	private function requiredWarnings(SiteSchemaIndex $index, Page $page, Section $section, string $block, ClientStructure $structure, array &$result): void
	{
		$fields = [];
		$this->flatten($structure, $section, null, $fields);
		$used = [];

		foreach ($fields as $field) {
			if ($key = $result['fields'][$field->id]['key'] ?? null) {
				$used[$key] = true;
			}
		}

		foreach ($index->fields($block) as $acf) {
			if ($acf['required'] && ! isset($used[$acf['key']]) && ($acf['scope'] === null || isset($used[$acf['scope']]))) {
				$result['warnings']['required'][] = ['page' => $page, 'label' => $section->name . ' › ' . $acf['display']];
			}
		}
	}

	/**
	 * Status do odznaki: green ≥ 0.8 (albo ręcznie), yellow 0.5–0.8, red poniżej, bez pary albo nieaktualne.
	 */
	private function status(bool $valid, ?SiteMapping $mapping, bool $stale, ?string $whenEmpty = null): string
	{
		return match (true) {
			$stale => 'stale',
			$valid && $mapping?->isManual() => 'manual',
			$valid && $mapping->confidence >= SiteMatcher::GREEN => 'green',
			$valid && $mapping->confidence >= SiteMatcher::YELLOW => 'yellow',
			! $valid && $whenEmpty !== null => $whenEmpty,
			default => 'red',
		};
	}

	/**
	 * Liczniki statusów podstrony (sekcje i pola): zielone (pewne i ręczne), żółte, czerwone.
	 *
	 * @return array{green: int, yellow: int, red: int}
	 */
	public static function counts(array $resolved, int $pageId): array
	{
		$counts = ['green' => 0, 'yellow' => 0, 'red' => 0];

		foreach ([...array_values($resolved['sections']), ...array_values($resolved['fields'])] as $entry) {
			if ($entry['page_id'] !== $pageId) {
				continue;
			}

			match ($entry['status']) {
				'green', 'manual' => $counts['green']++,
				'yellow' => $counts['yellow']++,
				'red', 'stale' => $counts['red']++,
				default => null,
			};
		}

		return $counts;
	}

	/**
	 * Listy wyboru dla ekranu przeglądu: strony schematu, bloki (najpierw z tej strony) i zgodne pola ACF.
	 */
	public function form(Page $page, array $resolved): array
	{
		$index = $resolved['index'];
		$pageEntry = $resolved['pages'][$page->id];
		$structure = $this->editor->structure($page, true);
		$pageOptions = [];

		foreach ($index->pages() as $id => $schemaPage) {
			$pageOptions[(string) $id] = sprintf('%s (/%s)', $schemaPage['title'] ?? $id, trim((string) ($schemaPage['path'] ?? $schemaPage['slug'] ?? ''), '/'));
		}

		$onPage = [];

		foreach ($index->pageBlocks($pageEntry['target']) as $i => $block) {
			$onPage[$block['name'] . '|' . $block['position']] = sprintf('%d. %s (%s)', $i + 1, $index->block($block['name'])['title'] ?? $block['name'], $block['name']);
		}

		$theme = [];

		foreach ($index->blocks() as $name => $block) {
			$theme[$name . '|'] = sprintf('%s (%s)', $block['title'] ?? $name, $name);
		}

		$sections = [];

		foreach ($structure->sections as $section) {
			$entry = $resolved['sections'][$section->id];
			$value = $entry['block'] !== null ? $entry['block'] . '|' . ($entry['position'] ?? '') : ($entry['stale'] ? (string) $entry['target'] . '|' : '');
			$fields = [];
			$all = [];
			$this->flatten($structure, $section, null, $all);

			foreach ($all as $field) {
				$row = $resolved['fields'][$field->id];
				$options = [];

				if ($entry['block'] !== null && $row['scope'] !== '__none__') {
					foreach ($index->scopeFields($entry['block'], $row['scope']) as $key => $acf) {
						if (SiteMatcher::compatibility($field->type->value, $acf['type']) !== null) {
							$options[$key] = $acf['display'];
						}
					}
				}

				$fields[] = $row + [
					'options' => $options,
					'value' => $row['key'] ?? ($row['stale'] ? (string) $row['target'] : ''),
				];
			}

			$sections[] = [
				'section' => $section,
				'entry' => $entry,
				'value' => $value,
				'fields' => $fields,
			];
		}

		return [
			'page' => $pageEntry,
			'page_options' => $pageOptions,
			'block_options' => ['Na tej stronie' => $onPage, 'Pozostałe bloki motywu' => $theme],
			'sections' => $sections,
		];
	}

	/* ---------------------------------------------------------------------
	 * Pomocnicze
	 * ------------------------------------------------------------------- */

	/**
	 * Wejście SiteMatcher z bazy (wszystkie aktywne podstrony, także ukryte sekcje i pola).
	 */
	public function input(Project $project): array
	{
		$pages = [];

		foreach ($this->editor->pages($project) as $page) {
			$structure = $this->editor->structure($page, true);
			$pages[] = [
				'id' => $page->id,
				'name' => $page->name,
				'slug' => (string) $page->slug,
				'post_type' => $this->acf->pageMapping($page, $page->mapping)['post_type'],
				'sections' => $structure->sections->map(fn (Section $section) => [
					'id' => $section->id,
					'name' => $section->name,
					'hidden' => (bool) $section->is_hidden,
					'fields' => $this->nodes($structure, $section, null),
				])->values()->all(),
			];
		}

		return ['pages' => $pages];
	}

	private function nodes(ClientStructure $structure, Section $section, ?Field $parent): array
	{
		return $structure->children($section, $parent)->map(fn (Field $field) => [
			'id' => $field->id,
			'label' => (string) $field->label,
			'key' => (string) $field->name,
			'type' => $field->type->value,
			'text' => $field->figma_text,
			'children' => $field->type->isContainer() ? $this->nodes($structure, $section, $field) : [],
		])->values()->all();
	}

	/**
	 * @param Field[] $fields
	 */
	public function flatten(ClientStructure $structure, Section $section, ?Field $parent, array &$fields): void
	{
		foreach ($structure->children($section, $parent) as $field) {
			$fields[] = $field;

			if ($field->type->isContainer()) {
				$this->flatten($structure, $section, $field, $fields);
			}
		}
	}

	private function findField(SiteSchemaIndex $index, string $key): ?array
	{
		foreach (array_keys($index->blocks()) as $block) {
			if ($field = $index->field($block, $key)) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Przykładowa wartość klienta dla każdego pola (pierwsza z treścią). Brak wpisu = pole bez treści.
	 *
	 * @return array<int, string>
	 */
	private function samples(Project $project): array
	{
		$samples = [];
		$types = Field::query()->where('project_id', $project->id)->pluck('type', 'id');

		FieldValue::query()
			->where('project_id', $project->id)
			->whereNotNull('value')
			->whereNotIn('value', ['', '[]'])
			->orderBy('id')
			->select(['id', 'field_id', 'value'])
			->chunk(1000, function ($values) use (&$samples, $types) {
				foreach ($values as $value) {
					if (isset($samples[$value->field_id]) || ! ContentService::hasContent($value->value)) {
						continue;
					}

					$type = $types[$value->field_id] ?? null;
					$type = $type instanceof FieldType ? $type : FieldType::tryFrom((string) $type);
					$list = fn () => count(ContentService::rowList($value));

					$samples[$value->field_id] = match ($type) {
						FieldType::Image, FieldType::Gallery => $list() > 0
							? sprintf('%d %s', $list(), $list() === 1 ? 'plik' : 'pliki')
							: (($link = ContentService::mediaLink($value)) ? 'link: ' . Str::limit($link, 60) : ''),
						FieldType::Repeater => sprintf('%d el.', $list()),
						FieldType::Link => (function () use ($value) {
							$link = (array) json_decode((string) $value->value, true);

							return trim(($link['label'] ?? '') . ' → ' . ($link['url'] ?? ''), ' →');
						})(),
						default => Str::limit(trim(html_entity_decode(wp_strip_all_tags((string) $value->value))), 80),
					};

					if ($samples[$value->field_id] === '' || $samples[$value->field_id] === '0 el.' || str_starts_with($samples[$value->field_id], '0 ')) {
						unset($samples[$value->field_id]);
					}
				}
			});

		return $samples;
	}
}
