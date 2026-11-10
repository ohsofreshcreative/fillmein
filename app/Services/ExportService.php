<?php

namespace App\Services;

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Enums\FieldType;
use App\Models\Asset;
use App\Models\Export;
use App\Models\Field;
use App\Models\Page;
use App\Models\Project;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Support\Actor;
use App\Support\ClientStructure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use WP_User;
use ZipArchive;

/**
 * Eksport projektu (całego albo wybranych podstron) do paczki ZIP. Format opisuje `docs/export-format.md`:
 * - `content.json` — uniwersalny format `fmi-content` (ContentExportBuilder), niezależny od WordPressa,
 * - `export.json` — profil ACF dla importera `fmi:import` (mapowanie z MappingController),
 * - `media/<sha256>.<rozszerzenie>` — pliki klienta, wspólne dla obu dokumentów.
 *
 * Do eksportu trafiają wyłącznie jawnie wybrane klucze: bez autorów, e-maili, tokenów i ścieżek dysku.
 * Kolejność wynika z pozycji i ID, więc ten sam stan daje tę samą treść poza `exported_at`.
 */
class ExportService
{
	/** Zmiana niezgodna wstecz wymaga podbicia części głównej i aktualizacji schematu. */
	public const SCHEMA_VERSION = '1.2';

	private const DISK = 'fmi';

	private const SUMMARY_MISSING_LIMIT = 50;

	public function __construct(
		private ClientEditorService $editor,
		private AcfMappingService $mapping,
		private ExportValidator $validator,
		private AssetService $assets,
		private ActivityLogger $activity,
		private ContentExportBuilder $content,
		private ContentExportValidator $contentValidator,
		private SiteMappingService $site,
	) {
	}

	/**
	 * Eksport z historią. Błąd kończy się statusem `failed`, a wiadomość dla pracownika nie zawiera ścieżek.
	 */
	public function run(Project $project, WP_User $by, ?array $pageUuids = null): Export
	{
		// Duży projekt: pliki trafiają do ZIP strumieniowo z dysku, ale łączny czas może przekroczyć domyślny limit.
		if (function_exists('set_time_limit')) {
			@set_time_limit(300);
		}

		$export = Export::create([
			'project_id' => $project->id,
			'type' => ExportType::Json,
			'status' => ExportStatus::Running,
			'user_id' => $by->ID,
			'started_at' => now(),
		]);

		try {
			$pages = $this->pagesFor($project, $pageUuids);

			if ($pages === []) {
				throw new RuntimeException('empty');
			}

			$payload = $this->build($project, $pages);
			$content = $this->content->build($project, $pages, $pageUuids === null);

			if ($this->validator->validate($payload) !== [] || $this->contentValidator->validate($content) !== []) {
				throw new RuntimeException('schema');
			}

			$this->store($export, $project, $payload, $content);
			$this->activity->log('export.completed', Actor::user($by), $project, $export, [
				'schema_version' => self::SCHEMA_VERSION,
				'content_schema_version' => ContentExportBuilder::SCHEMA_VERSION,
				'scope' => $content['scope']['type'],
				'pages' => count($pages),
			]);
		} catch (Throwable $e) {
			if (! in_array($e->getMessage(), ['schema', 'empty'], true)) {
				report($e);
			}

			$export->forceFill([
				'status' => ExportStatus::Failed,
				'summary' => ['scope' => $this->scopeOf($project, $pageUuids)],
				'error_message' => match ($e->getMessage()) {
					'schema' => 'Eksport nie przeszedł walidacji schematu.',
					'empty' => 'Wybrane podstrony nie istnieją albo są w archiwum.',
					default => 'Nie udało się zapisać eksportu.',
				},
				'finished_at' => now(),
			])->save();

			$this->activity->log('export.failed', Actor::user($by), $project, $export, []);
		}

		return $export;
	}

	/**
	 * Aktywne podstrony w kolejności drzewa; `null` — cały projekt, lista UUID — tylko te podstrony.
	 *
	 * @return Page[]
	 */
	public function pagesFor(Project $project, ?array $pageUuids = null): array
	{
		$pages = $this->editor->pages($project);

		if ($pageUuids !== null) {
			$pages = $pages->whereIn('uuid', $pageUuids)->values();
		}

		return $this->ordered($pages);
	}

	/**
	 * Zakres do historii: nazwy z chwili eksportu, bo podstrona może później zmienić nazwę albo trafić do archiwum.
	 */
	public function scopeOf(Project $project, ?array $pageUuids): array
	{
		if ($pageUuids === null) {
			return ['type' => 'project'];
		}

		return [
			'type' => 'pages',
			'pages' => array_map(fn (Page $page) => ['id' => $page->uuid, 'name' => $page->name], $this->pagesFor($project, $pageUuids)),
		];
	}

	/**
	 * @param Page[]|null $pages wynik pagesFor(); domyślnie cały projekt
	 */
	public function build(Project $project, ?array $pages = null): array
	{
		$sectionMappings = $this->mapping->mappings($project, (new Section)->getMorphClass());
		$context = [
			'pages' => [],
			'sections' => [],
			'fields' => [],
			'values' => [],
			'assets' => [],
			'required_empty' => [],
			'unmapped' => [],
			'field_mappings' => $this->mapping->mappings($project, (new Field)->getMorphClass()),
		];

		$pages ??= $this->pagesFor($project);
		// Projekt ze schematem strony WordPress: nazwy, ścieżki i bloki z dopasowania (SiteMappingService).
		$site = $this->site->resolve($project, $pages);
		$context['site'] = $site;
		$exported = array_column(array_map(fn (Page $page) => ['id' => $page->id], $pages), 'id', 'id');

		foreach ($pages as $page) {
			$structure = $this->editor->structure($page, true);
			$blocks = $this->mapping->blocks($structure->sections, $sectionMappings);

			$context['pages'][] = [
				'id' => $page->uuid,
				// Rodzic w archiwum nie trafia do eksportu, więc podstrona staje się korzeniem.
				'parent_id' => isset($exported[$page->parent_id]) ? $page->parent->uuid : null,
				'name' => $page->name,
				'slug' => $page->slug,
				'position' => (int) $page->position,
				'mapping' => $site ? $site['pages'][$page->id]['mapping'] : $this->mapping->pageMapping($page, $page->mapping),
			];

			foreach ($structure->sections as $section) {
				$context['sections'][] = [
					'id' => $section->uuid,
					'page_id' => $page->uuid,
					'name' => $section->name,
					'position' => (int) $section->position,
					'hidden' => (bool) $section->is_hidden,
					'mapping' => ($block = $site['sections'][$section->id]['block'] ?? null)
						? ['acf_block' => $block, 'occurrence' => (int) $site['sections'][$section->id]['occurrence']]
						: ['acf_block' => $blocks[$section->id]],
				] + (($note = \App\Models\SectionNote::query()->where('section_id', $section->id)->value('body')) ? ['client_note' => (string) $note] : []);

				$where = ['page' => $page, 'section' => $section];

				$this->describe($structure, $section, null, null, $where, $context);
				array_push($context['values'], ...$this->valuesOf($structure, $section, null, null, $where, $context));
			}
		}

		ksort($context['assets']);

		return [
			'schema_version' => self::SCHEMA_VERSION,
			'generator' => ['name' => 'fill-me-in', 'format' => 'fmi-export'],
			'exported_at' => now()->toIso8601ZuluString(),
			'project' => ['id' => $project->uuid, 'name' => $project->name, 'slug' => Str::slug($project->name) ?: 'projekt'],
		] + ($site ? ['schema' => [
			'site_url' => $site['schema']->site_url,
			'uploaded_at' => $site['schema']->uploaded_at?->toIso8601ZuluString(),
			'schema_version' => $site['schema']->schema_version,
		]] : []) + [
			'pages' => $context['pages'],
			'sections' => $context['sections'],
			'fields' => $context['fields'],
			'values' => $context['values'],
			'assets' => array_values($context['assets']),
			'missing' => ['required_empty' => $context['required_empty'], 'unmapped' => $context['unmapped']],
		];
	}

	public static function encode(array $payload): string
	{
		return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	}

	/**
	 * Pojedyncza podstrona po swoich rodzicach, rodzeństwo wg pozycji.
	 *
	 * @param Collection<int, Page> $pages
	 * @return Page[]
	 */
	private function ordered(Collection $pages): array
	{
		$known = $pages->keyBy('id');
		$byParent = $pages->groupBy(fn (Page $page) => $page->parent_id && isset($known[$page->parent_id]) ? $page->parent_id : 0);
		$ordered = [];

		$walk = function (int $parent) use (&$walk, $byParent, &$ordered) {
			foreach ($byParent[$parent] ?? [] as $page) {
				$ordered[] = $page;
				$walk($page->id);
			}
		};

		$walk(0);

		return $ordered;
	}

	private function describe(ClientStructure $structure, Section $section, ?Field $parent, ?string $path, array $where, array &$context): void
	{
		$children = $structure->children($section, $parent);
		$names = $this->mapping->names($children, $context['field_mappings']);

		foreach ($children as $field) {
			$acf = $this->mapping->resolve($field, $names[$field->id], $path, $context['field_mappings'][$field->id] ?? null);
			$site = $context['site']['fields'][$field->id] ?? null;
			$extra = [];

			if ($context['site']) {
				[$acf, $extra] = $this->siteAcf($field, $acf, $site);
			}

			$context['fields'][] = [
				'id' => $field->uuid,
				'section_id' => $section->uuid,
				'parent_id' => $parent?->uuid,
				'key' => $field->name,
				'label' => $field->label,
				'type' => $field->type->value,
				'required' => (bool) $field->is_required,
				'hidden' => (bool) $field->is_hidden,
				'instructions' => $field->instructions,
				'position' => (int) $field->position,
				'settings' => (object) ((array) $field->settings),
				'acf' => [
					'name' => $acf['name'],
					'type' => $acf['type'],
					'path' => $acf['path'],
					'mapped' => $acf['mapped'],
					'settings' => (object) $acf['settings'],
				] + $extra,
			];

			if (! $acf['mapped'] && empty($extra['transparent'])) {
				$context['unmapped'][] = $this->location($where, $field) + ['message' => $acf['message']]
					+ ($site ? ['label' => (string) $field->label, 'has_content' => (bool) $site['has_content']] : []);
			}

			if ($field->type->isContainer()) {
				$this->describe($structure, $section, $field, $this->mapping->childPath($field, $acf['path']), $where, $context);
			}
		}
	}

	/**
	 * Pole ACF ze schematu strony. Bez pary: nazwa i ścieżka domyślne (Figma), `mapped: false`; grupa z Figmy
	 * bez odpowiednika w ACF jest przezroczysta — nie jest brakiem, bo pola w środku mają własne pary.
	 *
	 * @return array{0: array, 1: array} [mapowanie jak AcfMappingService::resolve(), dodatkowe klucze `acf`]
	 */
	private function siteAcf(Field $field, array $fallback, ?array $site): array
	{
		$acf = $site['acf'] ?? null;

		if ($acf) {
			$settings = array_intersect_key((array) $acf['def'], array_flip(['required', 'return_format', 'min', 'max', 'maxlength', 'choices']));

			return [[
				'name' => $acf['name'],
				'type' => $acf['type'],
				'path' => $acf['path'],
				'settings' => $settings,
				'mapped' => true,
				'message' => null,
			], ['key' => $acf['key']]];
		}

		$message = match (true) {
			(bool) ($site['stale'] ?? false) => 'Dopasowanie nieaktualne — pola nie ma już w schemacie strony.',
			(bool) ($site['skipped'] ?? false) => 'Pominięte ręcznie („nie importuj”).',
			default => 'Brak pary w schemacie strony.',
		};

		return [
			['mapped' => false, 'message' => $message, 'settings' => []] + $fallback,
			['key' => null] + (! empty($site['transparent']) ? ['transparent' => true] : []),
		];
	}

	/**
	 * Wartości poziomu. Grupa nie ma własnej wartości, więc jej pola są na tym samym poziomie co ona.
	 */
	private function valuesOf(ClientStructure $structure, Section $section, ?Field $parent, ?RepeaterRow $row, array $where, array &$context): array
	{
		$values = [];

		foreach ($structure->children($section, $parent) as $field) {
			if ($field->type === FieldType::Group) {
				array_push($values, ...$this->valuesOf($structure, $section, $field, $row, $where, $context));

				continue;
			}

			$entry = ['field_id' => $field->uuid, 'field_type' => $field->type->value, 'value' => $this->value($structure, $section, $field, $row, $where, $context)];

			// Link do zdjęcia podany zamiast pliku (np. stock) — importer go nie pobiera, zespół dodaje plik ręcznie.
			if ($field->type->isMedia() && ($link = ContentService::mediaLink($structure->value($field, $row)))) {
				$entry['source_link'] = $link;
			}

			$values[] = $entry;
		}

		return $values;
	}

	private function value(ClientStructure $structure, Section $section, Field $field, ?RepeaterRow $row, array $where, array &$context): mixed
	{
		$stored = $structure->value($field, $row);
		$filled = ContentService::hasContent($stored?->value);

		if ($field->type === FieldType::Repeater) {
			$rows = $structure->rows($field, $row);
			$this->checkCount($field, $rows->count(), $row, $where, $context);
			$items = [];

			// Pętla, nie `map`: funkcja strzałkowa dostałaby kopię `$context` i braki z wierszy by przepadły.
			foreach ($rows->values() as $index => $child) {
				$items[] = ['id' => $child->uuid, 'position' => $index, 'values' => $this->valuesOf($structure, $section, $field, $child, $where, $context)];
			}

			return $items;
		}

		if ($field->type->isMedia()) {
			$assets = $structure->assets($field, $row);

			$assets->each(function (Asset $asset) use (&$context) {
				$context['assets'][$asset->uuid] = $this->assetEntry($asset);
			});

			$this->checkCount($field, $assets->count() + (ContentService::mediaLink($stored) ? 1 : 0), $row, $where, $context);

			if (! empty($field->settings['require_alt']) && $assets->contains(fn (Asset $asset) => blank($asset->alt))) {
				$context['required_empty'][] = $this->location($where, $field, $row) + ['reason' => 'missing_alt'];
			}

			$uuids = $assets->pluck('uuid')->all();

			return $field->type === FieldType::Image ? ($uuids[0] ?? null) : $uuids;
		}

		if ($field->is_required && ! $filled) {
			$context['required_empty'][] = $this->location($where, $field, $row) + ['reason' => 'empty'];
		}

		if (! $filled) {
			return null;
		}

		return $field->type === FieldType::Link ? (array) json_decode($stored->value, true) + ['label' => '', 'url' => ''] : $stored->value;
	}

	private function checkCount(Field $field, int $count, ?RepeaterRow $row, array $where, array &$context): void
	{
		$minimum = (int) ($field->settings['min_items'] ?? 0);

		if ($count === 0 && $field->is_required) {
			$context['required_empty'][] = $this->location($where, $field, $row) + ['reason' => 'empty'];
		} elseif ($count < $minimum) {
			$context['required_empty'][] = $this->location($where, $field, $row) + ['reason' => 'too_few'];
		}
	}

	private function location(array $where, Field $field, ?RepeaterRow $row = null): array
	{
		return array_filter([
			'page_id' => $where['page']->uuid,
			'page' => $where['page']->name,
			'section_id' => $where['section']->uuid,
			'section' => $where['section']->name,
			'field_id' => $field->uuid,
			'field' => $field->label,
			'row_id' => $row?->uuid,
		], fn ($value) => $value !== null);
	}

	private function assetEntry(Asset $asset): array
	{
		return [
			'id' => $asset->uuid,
			'sha256' => $asset->checksum,
			'mime' => $asset->mime_type,
			'width' => $asset->width,
			'height' => $asset->height,
			'size' => (int) $asset->size,
			'alt' => $asset->alt,
			'caption' => $asset->caption,
			'file_name' => $asset->original_name,
			'path' => ContentExportBuilder::mediaPath($asset),
		];
	}

	private function store(Export $export, Project $project, array $payload, array $content): void
	{
		$this->assets->protectStorage();

		$disk = Storage::disk(self::DISK);
		$directory = sprintf('exports/%s/%s', $project->uuid, $export->uuid);
		$json = self::encode($payload);
		$contentJson = self::encode($content);
		$jsonPath = $directory . '/export.json';

		foreach ([$jsonPath => $json, $directory . '/content.json' => $contentJson] as $path => $bytes) {
			$disk->put($path, $bytes);
			chmod($disk->path($path), 0600);
		}

		$zipSize = $this->package($disk->path($directory . '/export.zip'), $json, $contentJson, $payload);

		$export->forceFill([
			'status' => ExportStatus::Completed,
			'file_path' => $jsonPath,
			'checksum' => hash('sha256', $json),
			'finished_at' => now(),
			'summary' => [
				'schema_version' => self::SCHEMA_VERSION,
				'content_schema_version' => ContentExportBuilder::SCHEMA_VERSION,
				'scope' => $this->scopeOf($project, $content['scope']['type'] === 'project' ? null : $content['scope']['page_ids']),
				'counts' => $content['summary'],
				'content_checksum' => hash('sha256', $contentJson),
				'size' => strlen($json),
				'zip_size' => $zipSize,
				'pages' => count($payload['pages']),
				'fields' => count($payload['fields']),
				'assets' => count($payload['assets']),
				'required_empty' => count($payload['missing']['required_empty']),
				'unmapped' => count($payload['missing']['unmapped']),
				'missing' => [
					'required_empty' => array_slice($payload['missing']['required_empty'], 0, self::SUMMARY_MISSING_LIMIT),
					'unmapped' => array_slice($payload['missing']['unmapped'], 0, self::SUMMARY_MISSING_LIMIT),
				],
			],
		])->save();
	}

	/**
	 * Paczka: `content.json`, `export.json` i pliki w `media/`. Zdjęcia są już skompresowane, więc bez ponownej
	 * kompresji. `addFile` czyta plik z dysku dopiero przy zamykaniu, więc pamięć nie rośnie z liczbą zdjęć.
	 */
	private function package(string $target, string $json, string $contentJson, array $payload): int
	{
		$zip = new ZipArchive;

		if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new RuntimeException('Nie można utworzyć paczki.');
		}

		$zip->addFromString('content.json', $contentJson);
		$zip->addFromString('export.json', $json);

		$disk = Storage::disk(self::DISK);
		$stored = collect(array_chunk(array_column($payload['assets'], 'id'), 500))
			->flatMap(fn (array $uuids) => Asset::query()->whereIn('uuid', $uuids)->get(['id', 'uuid', 'path']))
			->keyBy('uuid');

		foreach ($payload['assets'] as $entry) {
			if (! $zip->addFile($disk->path($stored[$entry['id']]->path), $entry['path'])) {
				throw new RuntimeException('Brak pliku.');
			}

			$zip->setCompressionName($entry['path'], ZipArchive::CM_STORE);
		}

		if (! $zip->close()) {
			throw new RuntimeException('Nie można zapisać paczki.');
		}

		chmod($target, 0600);

		return (int) filesize($target);
	}
}
