<?php

namespace App\Services;

use App\Enums\ElementSource;
use App\Enums\FieldType;
use App\Exceptions\PageStructureException;
use App\Models\ClientAccess;
use App\Models\Field;
use App\Models\FieldValue;
use App\Models\Page;
use App\Models\Project;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use WP_User;

/**
 * Transakcyjne duplikowanie podstrony.
 *
 * Kopiujemy DEFINICJE (sekcje, pola, ustawienia) i opcjonalnie TREŚĆ (wartości, wiersze repeaterów).
 * Każdy wiersz dostaje nową tożsamość (nowy `id` i nowy `uuid`), więc duplikat jest całkowicie
 * niezależny od źródła — edycja jednego nie zmienia drugiego. Powiązania wewnętrzne
 * (`parent_id` pól, `parent_row_id` wierszy, `row_key` wartości, listy UUID w JSON)
 * są przemapowane na nowe identyfikatory.
 *
 * Pliki (fmi_assets) NIE są kopiowane — duplikat wskazuje te same zasoby przez referencję
 * (ten sam `asset_id` w fmi_field_value_assets i te same UUID w JSON wartości). Pliki należą
 * do projektu, a nie do podstrony, więc referencja jest bezpieczna i nie powiela danych na dysku.
 *
 * Duplikat ma `source = manual`: późniejszy import z Figmy nie może go usunąć ani nadpisać,
 * mimo że może wskazywać ten sam frame (`figma_frame_url`), co jest dopuszczone produktowo.
 *
 * Całość działa w jednej transakcji — błąd w dowolnym kroku wycofuje wszystkie wstawienia.
 */
class PageDuplicationService
{
	public function __construct(
		private PageService $pages,
		private ActivityLogger $activity,
	) {
	}

	/**
	 * Duplikowanie przez pracownika. Autoryzację sprawdza wywołujący (ProjectPolicy@configureFields).
	 *
	 * @param array{name?: ?string, parent_id?: ?int, with_values?: bool, with_children?: bool} $options
	 */
	public function duplicate(Page $page, array $options, WP_User $by): Page
	{
		return $this->run($page, $options, Actor::user($by), 'page.duplicated');
	}

	/**
	 * Duplikowanie przez klienta (bez konta). Autoryzuje wyłącznie token: dostęp musi być aktywny,
	 * dotyczyć projektu tej podstrony, a administrator musi jawnie włączyć `client_can_duplicate`.
	 * Klient nigdy nie kopiuje treści ani poddrzewa — dostaje czystą kopię struktury.
	 */
	public function duplicateForClient(Page $page, ClientAccess $access, ?string $name = null): Page
	{
		if (! $access->isActive()) {
			throw new PageStructureException('Link dostępowy jest nieaktywny.', 'token');
		}

		if ((int) $access->project_id !== (int) $page->project_id) {
			throw new PageStructureException('Podstrona nie należy do projektu tego linku.', 'token');
		}

		if (! $page->client_can_duplicate) {
			throw new PageStructureException('Administrator nie zezwolił na duplikowanie tej podstrony.', 'page_id');
		}

		if ($page->isArchived()) {
			throw new PageStructureException('Nie można duplikować zarchiwizowanej podstrony.', 'page_id');
		}

		return $this->run($page, [
			'name' => $name,
			'parent_id' => $page->parent_id,
			'with_values' => false,
			'with_children' => false,
		], Actor::client($access), 'page.duplicated_by_client');
	}

	/**
	 * @param array{name?: ?string, parent_id?: ?int, with_values?: bool, with_children?: bool} $options
	 */
	private function run(Page $page, array $options, Actor $actor, string $event): Page
	{
		$project = $page->project;
		$withValues = (bool) ($options['with_values'] ?? false);
		$withChildren = (bool) ($options['with_children'] ?? false);

		$parentId = array_key_exists('parent_id', $options) ? $options['parent_id'] : $page->parent_id;
		$parent = $this->parentInProject($page, $parentId);

		// Duplikat nie może wylądować we własnym poddrzewie, bo kopiowalibyśmy rosnącą strukturę.
		if ($parent && in_array((int) $parent->id, $this->pages->subtreeIds($page), true)) {
			throw new PageStructureException('Duplikat nie może trafić do podstrony kopiowanej ani jej podstrony podrzędnej.');
		}

		// Limit zagnieżdżenia liczony z kopiowanym poddrzewem (tylko aktywne podstrony są kopiowane).
		$this->pages->assertDepth($parent, $withChildren ? $this->pages->subtreeHeight($page, true) : 0);

		$copy = DB::transaction(function () use ($page, $project, $parent, $options, $withValues, $withChildren, $actor) {
			$copy = $this->copyPage($page, $project, $parent?->id, $options['name'] ?? null, $withValues, $actor);

			if ($withChildren) {
				$this->copyChildren($page, $copy, $withValues, $actor);
			}

			return $copy;
		});

		$this->activity->log($event, $actor, $project, $copy, [
			'page_name' => $copy->name,
			'source_page_id' => $page->id,
			'source_page_name' => $page->name,
			'with_values' => $withValues,
			'with_children' => $withChildren,
		]);

		return $copy;
	}

	/**
	 * Kopia pojedynczej podstrony wraz z sekcjami, polami i (opcjonalnie) treścią.
	 */
	private function copyPage(Page $page, Project $project, ?int $parentId, ?string $name, bool $withValues, Actor $actor): Page
	{
		$name = trim((string) ($name ?? '')) !== '' ? $name : $page->name . ' (kopia)';

		$copy = Page::create([
			'project_id' => $project->id,
			'parent_id' => $parentId,
			'name' => $name,
			'slug' => $this->pages->uniqueSlug($project, $parentId, $name),
			// `manual`, żeby import z Figmy nie zarządzał duplikatem; link do frame'a zostaje.
			'source' => ElementSource::Manual,
			'figma_node_id' => $page->figma_node_id,
			'figma_node_name' => $page->figma_node_name,
			'figma_frame_url' => $page->figma_frame_url,
			'position' => (int) Page::query()
				->where('project_id', $project->id)
				->where('parent_key', (int) ($parentId ?? 0))
				->max('position') + 1,
			'client_can_duplicate' => (bool) $page->client_can_duplicate,
			'duplicated_from_id' => $page->id,
		]);

		$fieldMap = [];

		foreach ($page->sections()->whereNull('archived_at')->orderBy('position')->get() as $section) {
			$sectionCopy = Section::create([
				'project_id' => $project->id,
				'page_id' => $copy->id,
				'name' => $section->name,
				'source' => $section->source,
				'figma_node_id' => $section->figma_node_id,
				'figma_node_name' => $section->figma_node_name,
				'figma_meta' => $section->figma_meta,
				'position' => $section->position,
				// Podgląd z Figmy to plik współdzielony w obrębie projektu — kopiujemy ścieżkę, nie plik.
				'preview_path' => $section->preview_path,
				'preview_width' => $section->preview_width,
				'preview_height' => $section->preview_height,
				'preview_updated_at' => $section->preview_updated_at,
				'is_hidden' => $section->is_hidden,
			]);

			$this->copyFields($section, $sectionCopy, null, null, $fieldMap);
		}

		if ($withValues && $fieldMap !== []) {
			$this->copyValues($fieldMap, $project, $actor);
		}

		return $copy;
	}

	/**
	 * Rekurencyjna kopia pól sekcji z przemapowaniem `parent_id`.
	 *
	 * @param array<int, int> $fieldMap stare id pola => nowe id pola (uzupełniane w miejscu)
	 */
	private function copyFields(Section $section, Section $sectionCopy, ?int $parentId, ?int $parentCopyId, array &$fieldMap): void
	{
		$fields = Field::query()
			->where('section_id', $section->id)
			->where('parent_id', $parentId)
			->whereNull('archived_at')
			->orderBy('position')
			->orderBy('id')
			->get();

		foreach ($fields as $field) {
			$copy = Field::create([
				'project_id' => $sectionCopy->project_id,
				'section_id' => $sectionCopy->id,
				'parent_id' => $parentCopyId,
				'type' => $field->type,
				'name' => $field->name,
				'label' => $field->label,
				'source' => $field->source,
				'depth' => $field->depth,
				'is_required' => $field->is_required,
				'is_hidden' => $field->is_hidden,
				'instructions' => $field->instructions,
				'figma_node_id' => $field->figma_node_id,
				'figma_text' => $field->figma_text,
				'figma_meta' => $field->figma_meta,
				'settings' => $field->settings,
				'position' => $field->position,
			]);

			$fieldMap[(int) $field->id] = (int) $copy->id;

			$this->copyFields($section, $sectionCopy, (int) $field->id, (int) $copy->id, $fieldMap);
		}
	}

	/**
	 * Kopia treści: najpierw wiersze repeaterów (potrzebne do przemapowania `row_key`),
	 * potem wartości pól wraz z powiązaniami do plików.
	 *
	 * @param array<int, int> $fieldMap stare id pola => nowe id pola
	 */
	private function copyValues(array $fieldMap, Project $project, Actor $actor): void
	{
		$rowMap = $this->copyRepeaterRows($fieldMap, $project, $actor);

		$values = FieldValue::query()
			->whereIn('field_id', array_keys($fieldMap))
			->with('assets')
			->orderBy('id')
			->get();

		$types = Field::query()
			->whereIn('id', array_keys($fieldMap))
			->pluck('type', 'id');

		foreach ($values as $value) {
			// `pluck` na modelu zwraca już rzutowany enum; przy surowej wartości rzutujemy ręcznie.
			$rawType = $types[$value->field_id] ?? null;
			$type = $rawType instanceof FieldType ? $rawType : FieldType::tryFrom((string) $rawType);
			$rowId = $value->row_id ? ($rowMap['id'][(int) $value->row_id] ?? null) : null;

			// Wartość z wiersza, którego nie udało się przemapować, byłaby osierocona.
			if ($value->row_id && ! $rowId) {
				continue;
			}

			$copy = FieldValue::create([
				'project_id' => $project->id,
				'field_id' => $fieldMap[(int) $value->field_id],
				'row_id' => $rowId,
				'row_key' => $rowId ? ($rowMap['uuid'][(int) $value->row_id] ?? '') : '',
				'value' => $this->remapValue($value->value, $type, $rowMap['byUuid']),
				// Duplikat startuje z wersją 0 — historia rewizji źródła go nie dotyczy.
				'version' => 0,
				'updated_by_type' => $actor->type->value,
				'updated_by_user_id' => $actor->userId,
				'updated_by_access_id' => $actor->clientAccessId,
			]);

			foreach ($value->assets as $asset) {
				// Ten sam plik, nowe powiązanie: referencja, nie kopia pliku na dysku.
				$copy->assets()->attach($asset->id, ['position' => (int) $asset->pivot->position]);
			}
		}
	}

	/**
	 * Kopia wierszy repeaterów z przemapowaniem `parent_row_id`.
	 *
	 * @param array<int, int> $fieldMap
	 * @return array{id: array<int, int>, uuid: array<int, string>, byUuid: array<string, string>}
	 */
	private function copyRepeaterRows(array $fieldMap, Project $project, Actor $actor): array
	{
		$map = ['id' => [], 'uuid' => [], 'byUuid' => []];

		$rows = RepeaterRow::query()
			->whereIn('field_id', array_keys($fieldMap))
			->orderBy('parent_row_id')
			->orderBy('position')
			->orderBy('id')
			->get();

		// Wiersze zagnieżdżone wymagają już przemapowanego rodzica — powtarzamy przebiegi,
		// aż wszystkie dadzą się wstawić (głębokość ograniczona przez fillmein.fields.max_depth).
		$pending = $rows->all();
		$guard = 0;

		while ($pending !== [] && $guard++ <= count($rows) + 1) {
			$deferred = [];

			foreach ($pending as $row) {
				$parentRowId = $row->parent_row_id ? ($map['id'][(int) $row->parent_row_id] ?? null) : null;

				if ($row->parent_row_id && ! $parentRowId) {
					$deferred[] = $row;

					continue;
				}

				$copy = RepeaterRow::create([
					'project_id' => $project->id,
					'field_id' => $fieldMap[(int) $row->field_id],
					'parent_row_id' => $parentRowId,
					'position' => $row->position,
					'created_by_type' => $actor->type->value,
					'created_by_user_id' => $actor->userId,
					'created_by_access_id' => $actor->clientAccessId,
				]);

				$map['id'][(int) $row->id] = (int) $copy->id;
				$map['uuid'][(int) $row->id] = (string) $copy->uuid;
				$map['byUuid'][(string) $row->uuid] = (string) $copy->uuid;
			}

			if (count($deferred) === count($pending)) {
				// Dalszy postęp niemożliwy (uszkodzone dane) — przerywamy, transakcja wycofa całość.
				throw new PageStructureException('Nie udało się odtworzyć struktury wierszy repeatera.', 'with_values');
			}

			$pending = $deferred;
		}

		return $map;
	}

	/**
	 * Wartość repeatera to lista UUID wierszy — po kopiowaniu musi wskazywać nowe wiersze.
	 * Wartości image/gallery (UUID plików) zostają bez zmian: pliki są współdzielone.
	 *
	 * @param array<string, string> $byUuid stary uuid wiersza => nowy uuid
	 */
	private function remapValue(?string $value, ?FieldType $type, array $byUuid): ?string
	{
		if ($value === null || $type !== FieldType::Repeater) {
			return $value;
		}

		$decoded = json_decode($value, true);

		if (! is_array($decoded)) {
			return $value;
		}

		$remapped = array_values(array_filter(array_map(
			fn ($uuid) => is_string($uuid) ? ($byUuid[$uuid] ?? null) : null,
			$decoded,
		)));

		return json_encode($remapped);
	}

	/**
	 * Kopiowanie poddrzewa podstron (opcja „razem z podstronami podrzędnymi”).
	 */
	private function copyChildren(Page $source, Page $target, bool $withValues, Actor $actor): void
	{
		foreach ($source->children()->whereNull('archived_at')->get() as $child) {
			$childCopy = $this->copyPage($child, $target->project, (int) $target->id, $child->name, $withValues, $actor);

			$this->copyChildren($child, $childCopy, $withValues, $actor);
		}
	}

	private function parentInProject(Page $page, ?int $parentId): ?Page
	{
		if (! $parentId) {
			return null;
		}

		$parent = Page::query()
			->where('project_id', $page->project_id)
			->whereKey($parentId)
			->first();

		if (! $parent) {
			throw new PageStructureException('Wybrana podstrona nadrzędna nie należy do tego projektu.');
		}

		if ($parent->isArchived()) {
			throw new PageStructureException('Nie można umieścić duplikatu pod zarchiwizowaną podstroną.');
		}

		return $parent;
	}
}
