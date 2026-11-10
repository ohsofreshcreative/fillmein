<?php

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\FieldType;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\ClientAccess;
use App\Models\Field;
use App\Models\FieldRevision;
use App\Models\FieldValue;
use App\Models\FigmaPreview;
use App\Models\Page;
use App\Models\Project;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Models\SectionNote;
use App\Support\Actor;
use App\Support\ClientStructure;
use App\Support\ProjectProgress;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use WP_User;

/**
 * Edytor treści: odczyt widocznej struktury i zapisy w zakresie projektu. Działa dla klienta (ClientAccess)
 * i pracownika (WP_User, po Policy); pracownik widzi też pola ukryte przed klientem.
 *
 * - Pole spoza projektu → 404. Pole z projektu, ale niewidoczne (archiwum, ukryte, także przez rodzica,
 *   sekcję lub podstronę) → 403.
 * - Wiersz musi należeć do najbliższego repeatera pola; wiersz usunięty, także przez przodka → 404.
 * - Klient zmienia wyłącznie wartości i wiersze, nigdy strukturę.
 * - Treść zapisuje ContentService, a dziennik dostaje tylko etykietę pola, bez wartości. Autozapis pola
 *   daje jeden wpis `content.updated` na okno `fillmein.content.activity_coalesce_seconds`.
 */
class ClientEditorService
{
	private const HISTORY_LIMIT = 200;

	public function __construct(
		private ContentService $content,
		private ActivityLogger $activity,
		private AssetService $assets,
		private FigmaFrameService $frames,
	) {
	}

	/**
	 * @return Collection<int, Page>
	 */
	public function pages(Project $project): Collection
	{
		return $project->pages()->whereNull('archived_at')->orderBy('position')->orderBy('id')->get();
	}

	public function structure(Page $page, bool $includeHidden = false): ClientStructure
	{
		$sections = $page->sections()
			->orderBy('position')
			->orderBy('id')
			->whereNull('archived_at')
			->when(! $includeHidden, fn ($query) => $query->where('is_hidden', false))
			->get();

		$fields = Field::query()
			->whereIn('section_id', $sections->modelKeys())
			->whereNull('archived_at')
			->when(! $includeHidden, fn ($query) => $query->where('is_hidden', false))
			->orderBy('position')
			->orderBy('id')
			->get();

		$values = FieldValue::query()->whereIn('field_id', $fields->modelKeys())->get();

		$media = $fields->filter(fn (Field $field) => $field->type->isMedia())->modelKeys();
		$assets = Asset::query()
			->whereIn('uuid', $values->whereIn('field_id', $media)->flatMap(fn (FieldValue $value) => ContentService::rowList($value)))
			->get()
			->keyBy('uuid');

		$rows = RepeaterRow::query()
			->whereIn('field_id', $fields->where('type', FieldType::Repeater)->modelKeys())
			->orderBy('position')
			->orderBy('id')
			->get();

		return new ClientStructure(
			$sections,
			$fields->groupBy(fn (Field $field) => $field->parent_id ?: 'section-' . $field->section_id),
			$values->keyBy(fn (FieldValue $value) => $value->field_id . '|' . $value->row_key),
			$rows->groupBy(fn (RepeaterRow $row) => $row->field_id . '|' . ($row->parent_row_id ?: 0)),
			$assets,
		);
	}

	/**
	 * Podglądy z Figmy dla sekcji podstrony. Sekcja ręczna i podstrona bez frame'u nie mają podglądu.
	 *
	 * @return Collection<int, FigmaPreview> klucz: ID sekcji
	 */
	public function sectionPreviews(Page $page, ClientStructure $structure): Collection
	{
		$previews = $this->frames->frameFor($page)?->previews()->downloadable()->get()->keyBy('node_id') ?? collect();

		return $structure->sections
			->filter(fn (Section $section) => isset($previews[$section->figma_node_id]))
			->mapWithKeys(fn (Section $section) => [$section->id => $previews[$section->figma_node_id]]);
	}

	/**
	 * Prostokąty pól na podglądzie sekcji (podświetlenie po kliknięciu pola): granice warstwy pola z zapisanego
	 * drzewa frame'u, względem granic warstwy sekcji (0–1). Podgląd sekcji jest renderowany dokładnie w jej granicach
	 * (`use_absolute_bounds`), więc te same proporcje pasują do obrazu. Pole ręczne lub bez warstwy — brak prostokąta.
	 * Dla pól w liście (repeaterze) prostokąt wskazuje warstwę wzorcową, czyli pierwszy element z projektu.
	 *
	 * @return array<int, array<int, array{x: float, y: float, w: float, h: float}>> klucz: ID sekcji → ID pola
	 */
	public function fieldHighlights(Page $page, ClientStructure $structure, Collection $previews): array
	{
		$document = $this->frames->frameFor($page)?->document;

		if (! is_array($document) || $previews->isEmpty()) {
			return [];
		}

		$boxes = [];
		$walk = function (array $node) use (&$walk, &$boxes): void {
			if (isset($node['id'], $node['box']['width'], $node['box']['height'])) {
				$boxes[$node['id']] = $node['box'];
			}

			foreach ((array) ($node['children'] ?? []) as $child) {
				if (is_array($child)) {
					$walk($child);
				}
			}
		};
		$walk($document);

		$sections = $structure->sections->filter(fn (Section $section) => isset($previews[$section->id], $boxes[$section->figma_node_id]));
		$fields = Field::query()
			->whereIn('section_id', $sections->pluck('id'))
			->whereNotNull('figma_node_id')
			->whereNull('archived_at')
			->get(['id', 'section_id', 'figma_node_id']);
		$result = [];

		foreach ($fields as $field) {
			$section = $boxes[$sections->firstWhere('id', $field->section_id)->figma_node_id];
			$box = $boxes[$field->figma_node_id] ?? null;

			if (! $box || (float) $section['width'] <= 0 || (float) $section['height'] <= 0) {
				continue;
			}

			$x = max(0.0, ((float) ($box['x'] ?? 0) - (float) ($section['x'] ?? 0)) / (float) $section['width']);
			$y = max(0.0, ((float) ($box['y'] ?? 0) - (float) ($section['y'] ?? 0)) / (float) $section['height']);
			$w = min(1.0 - $x, (float) $box['width'] / (float) $section['width']);
			$h = min(1.0 - $y, (float) $box['height'] / (float) $section['height']);

			if ($x >= 1 || $y >= 1 || $w <= 0 || $h <= 0) {
				continue;
			}

			$result[$field->section_id][$field->id] = ['x' => round($x, 5), 'y' => round($y, 5), 'w' => round($w, 5), 'h' => round($h, 5)];
		}

		return $result;
	}

	/**
	 * Podgląd widocznej sekcji projektu z lokalną kopią pliku. Sekcja obca lub niewidoczna → 404.
	 */
	public function sectionPreview(Project $project, int $sectionId): FigmaPreview
	{
		$section = Section::query()
			->where('project_id', $project->id)
			->whereKey($sectionId)
			->whereNull('archived_at')
			->where('is_hidden', false)
			->whereNotNull('figma_node_id')
			->first();
		$page = $section ? $this->pages($project)->firstWhere('id', $section->page_id) : null;
		$preview = $page ? $this->frames->previewFor($page, $section->figma_node_id) : null;

		abort_unless($preview && $this->frames->ensureLocal($preview), 404);

		return $preview;
	}

	public function visibleField(Project $project, int $id, bool $includeHidden = false): Field
	{
		$field = Field::query()->where('project_id', $project->id)->find($id);

		abort_unless($field !== null, 404);

		$section = $field->section;
		$visible = ! $section->isArchived() && ($includeHidden || ! $section->is_hidden) && ! $section->page->isArchived();

		for ($node = $field; $visible && $node; $node = $node->parent) {
			$visible = ! $node->isArchived() && ($includeHidden || ! $node->is_hidden);
		}

		abort_unless($visible, 403);

		return $field;
	}

	/**
	 * Uwagi pod sekcją: sekcja w zakresie projektu, nieusunięta, na nieusuniętej podstronie, a dla klienta widoczna.
	 */
	public function saveSectionNote(ClientAccess|WP_User $who, Project $project, int $sectionId, ?string $body, int $baseVersion, bool $includeHidden = false): SectionNote
	{
		$section = Section::query()->where('project_id', $project->id)->find($sectionId);

		abort_unless($section !== null, 404);
		abort_unless(! $section->isArchived() && ($includeHidden || ! $section->is_hidden) && ! $section->page->isArchived(), 403);

		$note = $this->content->saveSectionNote($section, $body, $baseVersion, $this->actor($who));

		if ($note->wasChanged('version')) {
			$this->activity->log('content.section_note', $this->actor($who), $project, $section, ['page_name' => $section->page->name]);
		}

		return $note;
	}

	/**
	 * @return Collection<int, SectionNote> klucz: ID sekcji
	 */
	public function sectionNotes(ClientStructure $structure): Collection
	{
		return SectionNote::query()->whereIn('section_id', $structure->sections->pluck('id'))->get()->keyBy('section_id');
	}

	/**
	 * Wiersz najbliższego repeatera pola. Pole poza repeaterem nie przyjmuje wiersza.
	 */
	public function contextRow(Field $field, ?string $uuid): ?RepeaterRow
	{
		$repeater = $field->closestRepeater();

		if (! $repeater) {
			if ($uuid !== null) {
				throw ValidationException::withMessages(['row' => 'To pole nie należy do listy.']);
			}

			return null;
		}

		if ($uuid === null) {
			throw ValidationException::withMessages(['row' => 'Wskaż element listy.']);
		}

		$row = $this->findRow($repeater, $uuid);

		abort_unless($row !== null, 404);

		return $row;
	}

	/**
	 * @param array{value?: ?string, label?: ?string, url?: ?string} $input
	 */
	public function saveValue(ClientAccess|WP_User $who, Field $field, ?RepeaterRow $row, array $input, int $baseVersion): FieldValue
	{
		if (! $field->type->storesPlainValue()) {
			throw ValidationException::withMessages(['value' => 'Tego pola nie można zapisać w ten sposób.']);
		}

		$value = $this->content->save($field, $this->content->normalize($field, $input), $baseVersion, $this->actor($who), $row);

		if ($value->version === $baseVersion + 1) {
			$this->logUpdate($who, $field);
		}

		return $value;
	}

	/**
	 * @return array{0: RepeaterRow, 1: FieldValue}
	 */
	public function addRow(ClientAccess|WP_User $who, Field $repeater, ?RepeaterRow $parentRow, int $baseVersion): array
	{
		$this->assertRepeater($repeater);

		$result = $this->content->addRow($repeater, $baseVersion, $this->actor($who), $parentRow);

		$this->log('content.row_added', $who, $repeater);

		return $result;
	}

	public function removeRow(ClientAccess|WP_User $who, Field $repeater, ?RepeaterRow $parentRow, string $uuid, int $baseVersion): FieldValue
	{
		$this->assertRepeater($repeater);

		$row = $this->findRow($repeater, $uuid);

		abort_unless($row !== null && (int) $row->parent_row_id === (int) $parentRow?->id, 404);

		$value = $this->content->removeRow($repeater, $row, $baseVersion, $this->actor($who), $parentRow);

		$this->log('content.row_removed', $who, $repeater);

		return $value;
	}

	/**
	 * @param string[] $uuids
	 */
	public function reorderRows(ClientAccess|WP_User $who, Field $repeater, ?RepeaterRow $parentRow, array $uuids, int $baseVersion): FieldValue
	{
		$this->assertRepeater($repeater);

		$value = $this->content->reorderRows($repeater, $uuids, $baseVersion, $this->actor($who), $parentRow);

		$this->log('content.rows_reordered', $who, $repeater);

		return $value;
	}

	/**
	 * Przyjęcie pliku i przypięcie do pola. Brak miejsca sprawdzamy przed zapisem na dysk, a konflikt
	 * po nim: plik zostaje w magazynie, a ponowienie trafi na tę samą sumę kontrolną.
	 */
	public function uploadAsset(ClientAccess|WP_User $who, Field $field, ?RepeaterRow $row, ?UploadedFile $file, int $baseVersion): array
	{
		$this->assertMedia($field);

		if ($file === null) {
			throw ValidationException::withMessages(['file' => 'Wybierz plik.']);
		}

		$list = ContentService::rowList($this->valueOf($field, $row));

		if ($field->type === FieldType::Gallery && count($list) >= ContentService::galleryLimit($field)) {
			throw ValidationException::withMessages(['file' => sprintf('To pole może mieć najwyżej %d zdjęć.', ContentService::galleryLimit($field))]);
		}

		$actor = $this->actor($who);
		$asset = $this->assets->store($file, $field, $actor);
		$value = $this->content->attachAsset($field, $asset, $baseVersion, $actor, $row);

		$this->log('content.asset_added', $who, $field);

		return [$asset, $value];
	}

	public function detachAsset(ClientAccess|WP_User $who, Field $field, ?RepeaterRow $row, string $uuid, int $baseVersion): FieldValue
	{
		$this->assertMedia($field);

		$value = $this->content->detachAsset($field, $this->attachedAsset($field, $row, $uuid), $baseVersion, $this->actor($who), $row);

		$this->log('content.asset_removed', $who, $field);

		return $value;
	}

	/**
	 * @param string[] $uuids
	 */
	public function reorderAssets(ClientAccess|WP_User $who, Field $field, ?RepeaterRow $row, array $uuids, int $baseVersion): FieldValue
	{
		$this->assertMedia($field);

		$value = $this->content->reorderAssets($field, $uuids, $baseVersion, $this->actor($who), $row);

		$this->log('content.assets_reordered', $who, $field);

		return $value;
	}

	/**
	 * Link do zdjęcia zamiast pliku (np. ze stocka). Pusty adres usuwa link.
	 */
	public function saveMediaLink(ClientAccess|WP_User $who, Field $field, ?RepeaterRow $row, ?string $url, int $baseVersion): FieldValue
	{
		$this->assertMedia($field);

		$value = $this->content->saveMediaLink($field, $url, $baseVersion, $this->actor($who), $row);
		$this->logUpdate($who, $field);

		return $value;
	}

	public function updateAlt(ClientAccess|WP_User $who, Field $field, ?RepeaterRow $row, string $uuid, ?string $alt): Asset
	{
		$this->assertMedia($field);

		$asset = $this->attachedAsset($field, $row, $uuid);

		$asset->update(['alt' => trim((string) $alt) ?: null]);
		$this->logUpdate($who, $field);

		return $asset;
	}

	public function projectAsset(Project $project, string $uuid): Asset
	{
		$asset = Asset::query()->where('project_id', $project->id)->where('uuid', $uuid)->first();

		abort_unless($asset !== null, 404);

		return $asset;
	}

	/**
	 * Wartość pola z rewizjami, od najnowszej. Listy i pliki nie mają historii do przywracania.
	 *
	 * @return array{value: ?FieldValue, revisions: Collection<int, FieldRevision>}
	 */
	public function history(Field $field, ?RepeaterRow $row): array
	{
		abort_unless($field->type->storesPlainValue(), 404);

		$value = $this->valueOf($field, $row);

		return [
			'value' => $value,
			'revisions' => $value
				? $value->revisions()->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get()
				: collect(),
		];
	}

	public function restore(ClientAccess|WP_User $who, Field $field, ?RepeaterRow $row, int $revisionId, int $baseVersion): FieldValue
	{
		abort_unless($field->type->storesPlainValue(), 404);

		$value = $this->valueOf($field, $row);
		$revision = $value?->revisions()->whereKey($revisionId)->first();

		abort_unless($revision !== null, 404);

		$restored = $this->content->restore($value, $revision, $baseVersion, $this->actor($who));

		if ($restored->version === $baseVersion + 1) {
			$this->log('content.restored', $who, $field, ['version' => $revision->version]);
		}

		return $restored;
	}

	/**
	 * Autor rewizji: klient nie widzi nazwisk zespołu, pracownik widzi je wszystkie.
	 */
	public function author(FieldRevision $revision, bool $forStaff): string
	{
		if ($revision->actor_type === ActorType::Client) {
			return 'Klient';
		}

		if (! $forStaff) {
			return 'Zespół OhSoFresh';
		}

		return get_userdata((int) $revision->actor_user_id)->display_name ?? 'Zespół OhSoFresh';
	}

	/**
	 * @return array{project: array, page: array, section-id: array}
	 */
	public function progress(Field $field): array
	{
		$section = $field->section;
		$progress = ProjectProgress::forProject($field->project_id);
		$empty = ['total' => 0, 'filled' => 0, 'percent' => 0, 'required' => false];
		$result = [
			'project' => $progress['project'],
			'page' => $progress['pages'][$section->page_id] ?? $empty,
			'section-' . $section->id => $progress['sections'][$section->id] ?? $empty,
		];

		return array_map(fn (array $stats) => $stats + ['hint' => ProjectProgress::hint($stats)], $result);
	}

	private function findRow(Field $repeater, string $uuid): ?RepeaterRow
	{
		$row = RepeaterRow::query()
			->where('project_id', $repeater->project_id)
			->where('field_id', $repeater->id)
			->where('uuid', $uuid)
			->first();

		// Wiersz przodka usunięty miękko: relacja zwraca null, więc cała gałąź jest niedostępna.
		for ($node = $row; $node?->parent_row_id; $node = $node->parentRow) {
			if (! $node->parentRow) {
				return null;
			}
		}

		return $row;
	}

	private function assertMedia(Field $field): void
	{
		if (! $field->type->isMedia()) {
			throw ValidationException::withMessages(['file' => 'To pole nie przyjmuje plików.']);
		}
	}

	private function attachedAsset(Field $field, ?RepeaterRow $row, string $uuid): Asset
	{
		abort_unless(in_array($uuid, ContentService::rowList($this->valueOf($field, $row)), true), 404);

		return $this->projectAsset($field->project, $uuid);
	}

	private function assertRepeater(Field $field): void
	{
		if ($field->type !== FieldType::Repeater) {
			throw ValidationException::withMessages(['row' => 'To pole nie jest listą.']);
		}
	}

	private function valueOf(Field $field, ?RepeaterRow $row): ?FieldValue
	{
		return FieldValue::query()->where('field_id', $field->id)->where('row_key', $row?->uuid ?? '')->first();
	}

	private function actor(ClientAccess|WP_User $who): Actor
	{
		return $who instanceof WP_User ? Actor::user($who) : Actor::client($who);
	}

	private function logUpdate(ClientAccess|WP_User $who, Field $field): void
	{
		$actor = $this->actor($who);
		$window = (int) config('fillmein.content.activity_coalesce_seconds', 300);

		$recent = $window > 0 && Activity::query()
			->where('project_id', $field->project_id)
			->where('event', 'content.updated')
			->where('subject_type', $field->getMorphClass())
			->where('subject_id', $field->id)
			->where('actor_type', $actor->type)
			->where('user_id', $actor->userId)
			->where('client_access_id', $actor->clientAccessId)
			->where('created_at', '>=', now()->subSeconds($window))
			->exists();

		if (! $recent) {
			$this->log('content.updated', $who, $field);
		}
	}

	private function log(string $event, ClientAccess|WP_User $who, Field $field, array $properties = []): void
	{
		$this->activity->log($event, $this->actor($who), $field->project, $field, $properties + [
			'page_name' => $field->section->page->name,
			'field_label' => $field->label,
		]);
	}
}
