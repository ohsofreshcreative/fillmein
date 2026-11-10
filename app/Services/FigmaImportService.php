<?php

namespace App\Services;

use App\Enums\ElementSource;
use App\Enums\FieldType;
use App\Exceptions\FigmaImportException;
use App\Integrations\Figma\FrameAnalyzer;
use App\Models\Field;
use App\Models\FieldValue;
use App\Models\FigmaFrame;
use App\Models\FigmaNodeRule;
use App\Models\Page;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Support\Actor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use WP_User;

/**
 * Import sekcji i pól z frame'u Figmy podstrony: podgląd zmian (diff) → zatwierdzenie przez pracownika.
 *
 * - Dopasowanie po `figma_node_id` w obrębie podstrony (sekcje) i jej sekcji (pola), z rozróżnieniem
 *   pola i kontenera (grupa/repeater), więc ponowny import nie tworzy duplikatów, a UUID zostają.
 * - Elementy `manual` nie są przez import zmieniane. Sekcja lub kontener z Figmy, w którym są aktywne
 *   pola ręczne, nie jest archiwizowany, nawet gdy zniknął z pliku.
 * - Diff porównuje Figmę z Figmą: `figma_meta.proposed` trzyma etykietę, typ i pozycję z ostatniego importu.
 *   Zmiana w pliku trafia do podglądu, a ustawienia zespołu nie są zmianą i zostają, także gdy Figma się zmieni.
 *   Brakujące → `archived_at` (treść w fmi_field_values zostaje).
 * - Sam box, indeks czy styl warstwy to dane techniczne: odświeżają się przy zatwierdzeniu, ale nie są zmianą.
 * - Pracownik wybiera zmiany w podglądzie. Zmiana typu pola z treścią jest osobnym, domyślnie odznaczonym wyborem.
 * - Kolejność wynika z najdłuższego rosnącego podciągu, więc nowa warstwa nie oznacza sąsiadów jako przeniesionych.
 *   Przestawiane są tylko elementy `figma`, na pozycjach, które już zajmują: ręczne nie zmieniają ani jednej kolumny.
 * - Kontener z polami ręcznymi nie jest przenoszony, bo przeniesienie zmieniłoby te pola.
 * - Frame przycięty (`truncated`) niczego nie archiwizuje: brak warstwy niczego wtedy nie dowodzi.
 * - Pole przeniesione do innego repeatera (lub z/do repeatera) dostaje nowe pole, a stare trafia do
 *   archiwum — wartości są przypisane do wierszy konkretnego repeatera.
 * - Zatwierdzenie przyjmuje odcisk podglądu; gdy stan frame'u lub podstrony zmienił się od podglądu,
 *   import jest odrzucany (pracownik zatwierdza dokładnie to, co widział).
 *
 * Autoryzację (ProjectPolicy@configureFields) sprawdza wywołujący.
 */
class FigmaImportService
{
	public function __construct(
		private FigmaFrameService $frames,
		private ActivityLogger $activity,
		private ContentService $content,
	) {
	}

	/**
	 * @return array<string, string> ID warstwy => include|exclude
	 */
	public function rules(Page $page): array
	{
		return FigmaNodeRule::query()->where('page_id', $page->id)->pluck('action', 'node_id')->all();
	}

	public function plan(Page $page): array
	{
		$frame = $this->frames->frameFor($page);

		if (! $frame || ! is_array($frame->document)) {
			throw new FigmaImportException('Najpierw pobierz frame tej podstrony z Figmy.');
		}

		$sections = Section::query()->where('page_id', $page->id)->orderBy('position')->orderBy('id')->get();
		$fields = Field::query()->whereIn('section_id', $sections->modelKeys())->orderBy('position')->orderBy('id')->get();
		$patterns = $fields->where('type', FieldType::Repeater)
			->map(fn (Field $field) => $field->figma_meta['pattern'] ?? $field->figma_node_id)
			->filter()
			->values()
			->all();

		$analysis = (new FrameAnalyzer($this->rules($page), null, null, $patterns))->analyze($frame->document);
		$filled = FieldValue::query()
			->whereIn('field_id', $fields->modelKeys())
			->whereNotNull('value')
			->whereNotIn('value', ['', '[]'])
			->distinct()
			->pluck('field_id')
			->flip()
			->all();

		$state = new FigmaImportState($sections, $fields, $filled);
		$entries = [];

		foreach ($analysis['sections'] as $proposal) {
			$entries[] = $this->planSection($proposal, $state);
		}

		$this->markOrder($entries);

		$truncated = $this->isTruncated($frame->document);
		$archive = $this->planArchive($state, $truncated);
		$counts = $this->counts($entries, $archive);
		$keys = $this->keys($entries);
		$keys['archive'] = array_column(array_filter($archive, fn ($item) => $item['action'] === 'archive'), 'key');

		return [
			'frame' => $frame,
			'analysis' => $analysis,
			'sections' => $entries,
			'archive' => $archive,
			'manual' => [
				'sections' => $sections->where('source', ElementSource::Manual)->whereNull('archived_at')->values(),
				'fields' => $fields->where('source', ElementSource::Manual)->whereNull('archived_at')->count(),
			],
			'counts' => $counts,
			'truncated' => $truncated,
			'has_changes' => $counts['create'] + $counts['update'] + $counts['restore'] + $counts['archive'] > 0,
			'defaults' => [...$keys['changes'], ...$keys['archive']],
			'selectable' => [...$keys['changes'], ...$keys['archive'], ...$keys['risky']],
			'fingerprint' => $this->fingerprint($frame, $analysis, $sections, $fields, $keys['risky']),
		];
	}

	/**
	 * Zastosowanie zatwierdzonego podglądu. Zwraca liczniki wykonanych operacji.
	 *
	 * @param ?string[] $selection klucze wybranych zmian; null oznacza domyślny wybór podglądu
	 */
	public function apply(Page $page, WP_User $by, string $fingerprint, ?array $selection = null): array
	{
		$actor = Actor::user($by);

		$result = DB::transaction(function () use ($page, $fingerprint, $selection, $actor) {
			// Blokada wiersza podstrony: dwa równoległe zatwierdzenia nie utworzą dwóch kompletów pól.
			Page::query()->whereKey($page->id)->lockForUpdate()->first();

			$plan = $this->plan($page);

			if (! hash_equals($plan['fingerprint'], $fingerprint)) {
				throw new FigmaImportException('Struktura frame\'u lub podstrony zmieniła się od wyświetlenia podglądu. Sprawdź zmiany ponownie i zatwierdź.');
			}

			$selected = array_flip($selection ?? $plan['defaults']);

			if ($selection !== null && $plan['has_changes'] && array_intersect_key($selected, array_flip($plan['selectable'])) === []) {
				throw new FigmaImportException('Wybierz co najmniej jedną zmianę do zastosowania.');
			}

			$now = now();
			$sequence = [];

			foreach ($plan['sections'] as $entry) {
				$chosen = $this->chosen($entry, $selected, true);
				$section = $this->applySection($page, $entry, $chosen);

				if ($section) {
					$this->applyFields($entry['fields'], $section, null, 0, $selected, $chosen, $actor);
					$sequence[] = [$section, $chosen && $this->placed($entry)];
				}
			}

			$this->arrange(
				Section::query()->where('page_id', $page->id)->where('source', ElementSource::Figma->value)->whereNull('archived_at')->orderBy('position')->orderBy('id')->get(),
				$sequence,
			);

			foreach ($plan['archive'] as $item) {
				if ($item['action'] === 'archive' && isset($selected[$item['key']])) {
					$item['model']->forceFill(['archived_at' => $now])->save();
				}
			}

			$this->seedRows($page, $actor);

			$plan['applied'] = $this->counts($plan['sections'], $plan['archive'], $selected);

			return $plan;
		});

		$this->activity->log('figma.import_applied', $actor, $page->project, $page, [
			'page_name' => $page->name,
			'node_id' => $result['frame']->node_id,
			'file_version' => $result['frame']->file_version,
			'counts' => $result['applied'],
		]);

		return $result['applied'];
	}

	/**
	 * Lista z Figmy startuje z tyloma elementami, ile ma ich projekt, bo pola pustej listy są dla klienta
	 * niewidoczne. Listy z zapisaną wartością nie ruszamy, także gdy klient usunął z niej wszystko.
	 */
	private function seedRows(Page $page, Actor $actor): void
	{
		$repeaters = Field::query()
			->whereIn('section_id', $page->sections()->whereNull('archived_at')->pluck('id'))
			->where('type', FieldType::Repeater->value)
			->where('source', ElementSource::Figma->value)
			->where('is_hidden', false)
			->whereNull('archived_at')
			->orderBy('depth')
			->orderBy('position')
			->get();

		foreach ($repeaters as $repeater) {
			$owner = $this->owningRepeater($repeater);
			$parents = $owner ? RepeaterRow::query()->where('field_id', $owner->id)->get() : collect([null]);
			$limit = (int) ($repeater->settings['max_items'] ?? config('fillmein.fields.max_items', 100));
			$count = min(count($repeater->figma_meta['items'] ?? []), $limit);

			foreach ($parents as $parentRow) {
				$touched = FieldValue::query()
					->where('field_id', $repeater->id)
					->where('row_key', $parentRow?->uuid ?? '')
					->exists();

				if ($touched) {
					continue;
				}

				$version = 0;

				for ($i = 0; $i < $count; $i++) {
					[, $value] = $this->content->addRow($repeater, $version, $actor, $parentRow);
					$version = $value->version;
				}
			}
		}
	}

	/**
	 * Przyciski z projektu ustawia zespół, więc klient ich nie widzi: ani pola, ani listy złożonej z samych przycisków.
	 * Zespół może je odkryć w konfiguracji, a kolejny import tego nie cofnie.
	 */
	private function onlyLinks(array $proposal): bool
	{
		if ($proposal['type'] === FieldType::Link->value) {
			return true;
		}

		$children = $proposal['children'] ?? [];

		return $children !== [] && array_filter($children, fn (array $child) => ! $this->onlyLinks($child)) === [];
	}

	private function owningRepeater(Field $field): ?Field
	{
		for ($parent = $field->parent; $parent; $parent = $parent->parent) {
			if ($parent->type === FieldType::Repeater) {
				return $parent;
			}
		}

		return null;
	}

	/**
	 * Wykluczenie warstwy (`exclude`), przywrócenie wykluczonej automatycznie (`include`)
	 * albo powrót do reguł automatycznych (`reset`). Tylko warstwy z frame'u tej podstrony.
	 */
	public function setRule(Page $page, string $nodeId, string $action, WP_User $by): void
	{
		$frame = $this->frames->frameFor($page);
		$node = $frame && is_array($frame->document) ? $this->findNode($frame->document, $nodeId) : null;

		if (! $node || $nodeId === $frame->node_id) {
			throw new FigmaImportException('We frame\'ie tej podstrony nie ma takiej warstwy.');
		}

		if ($action === 'reset') {
			FigmaNodeRule::query()->where('page_id', $page->id)->where('node_id', $nodeId)->delete();
		} else {
			FigmaNodeRule::query()->updateOrCreate(
				['page_id' => $page->id, 'node_id' => $nodeId],
				[
					'project_id' => $page->project_id,
					'node_name' => mb_substr((string) ($node['name'] ?? ''), 0, 190) ?: null,
					'action' => $action === FigmaNodeRule::INCLUDE ? FigmaNodeRule::INCLUDE : FigmaNodeRule::EXCLUDE,
					'created_by' => $by->ID,
				],
			);
		}

		$this->activity->log('figma.node_rule_changed', Actor::user($by), $page->project, $page, [
			'page_name' => $page->name,
			'node_id' => $nodeId,
			'node_name' => mb_substr((string) ($node['name'] ?? ''), 0, 190),
			'action' => $action,
		]);
	}

	private function planSection(array $proposal, FigmaImportState $state): array
	{
		$model = $state->claimSection($proposal['node_id']);
		$entry = ['proposal' => $proposal, 'model' => $model, 'changes' => [], 'fields' => [], 'key' => 's:' . $proposal['node_id'], 'label_kept' => false];

		if ($model && $model->source === ElementSource::Manual) {
			// Sekcja ręczna z tym samym ID warstwy (np. po zmianie pochodzenia) — import jej nie dotyka.
			$entry['action'] = 'skipped';
			$entry['key'] = null;

			return $entry;
		}

		if ($model) {
			$baseline = $model->figma_meta['proposed'] ?? null;
			$entry['label_kept'] = $this->labelKept($model->name, $baseline, $proposal['name']);

			if ($this->labelChanged($model->name, $baseline, $proposal['name'])) {
				$entry['changes'][] = 'label';
			} elseif ($model->figma_node_name !== ($proposal['meta']['node_name'] ?? null)) {
				$entry['changes'][] = 'name';
			}

			if ($baseline === null) {
				$entry['changes'][] = 'meta';
			}
		}

		$entry['action'] = match (true) {
			! $model => 'create',
			$model->archived_at !== null => 'restore',
			$entry['changes'] !== [] => 'update',
			default => 'unchanged',
		};

		$entry['fields'] = $this->planFields($proposal['fields'], $state, $model?->id, null, null, in_array($entry['action'], ['create', 'restore'], true), false);

		return $entry;
	}

	/**
	 * @param ?int $sectionId istniejąca sekcja docelowa (null = nowa)
	 * @param ?int $parentId istniejące pole nadrzędne (null = poziom sekcji lub nowe pole nadrzędne)
	 * @param ?string $repeaterKey klucz najbliższego repeatera w propozycji
	 * @param bool $inherits elementy idą za nowym lub przywracanym rodzicem, więc nie mają własnego wyboru
	 * @param bool $rebased rodzic to repeater ze zmienionym wzorcem: ID warstw jego pól są nowe
	 */
	private function planFields(array $proposals, FigmaImportState $state, ?int $sectionId, ?int $parentId, ?string $repeaterKey, bool $inherits, bool $rebased): array
	{
		$entries = [];

		foreach ($proposals as $proposal) {
			$container = $proposal['role'] === 'container';
			$isRepeater = $proposal['type'] === FieldType::Repeater->value;
			$key = FigmaImportState::key($proposal['node_id'], $container);
			$accepts = fn (Field $field) => $state->repeaterKey($field) === $repeaterKey;
			$model = $state->claimField($key, $accepts);
			$rebasedHere = false;

			if (! $model && $isRepeater) {
				$model = $state->claimRepeaterByItems(array_column($proposal['meta']['items'] ?? [], 'node_id'), $accepts);
				$rebasedHere = $model !== null;
			}

			if (! $model && $rebased && $parentId) {
				$model = $state->claimSibling($parentId, (string) ($proposal['meta']['node_name'] ?? ''), $container);
			}

			$changes = [];
			$blocked = false;
			$typeChanged = false;
			$labelKept = false;

			if ($model) {
				$baseline = $model->figma_meta['proposed'] ?? null;
				$labelKept = $this->labelKept($model->label, $baseline, $proposal['label']);

				if ($model->figma_text !== $proposal['figma_text']) {
					$changes[] = 'text';
				}

				if ($this->labelChanged($model->label, $baseline, $proposal['label'])) {
					$changes[] = 'label';
				}

				// Typ zmieniony przez zespół zostaje; kontenera nie zmieniamy, bo jego dzieci zależą od typu.
				if ($baseline !== null && ! $container && $baseline['type'] !== $proposal['type'] && $model->type->value === $baseline['type']) {
					$typeChanged = true;
					$changes[] = 'type';
				}

				if ((int) $model->section_id !== (int) $sectionId || ($model->parent_id ? (int) $model->parent_id : null) !== $parentId) {
					$blocked = $model->type->isContainer() && $state->holdsManual($model);

					if (! $blocked) {
						$changes[] = 'move';
					}
				}

				if ($model->figma_node_id !== $proposal['node_id']) {
					$changes[] = 'pattern';
				}

				if ($isRepeater && ($model->figma_meta['items'] ?? null) != ($proposal['meta']['items'] ?? null)) {
					$changes[] = 'items';
				}

				if ($baseline === null) {
					$changes[] = 'meta';
				}
			}

			$action = match (true) {
				! $model => 'create',
				$model->archived_at !== null => 'restore',
				$changes !== [] => 'update',
				default => 'unchanged',
			};

			$entries[] = [
				'proposal' => $proposal,
				'model' => $model,
				'changes' => $changes,
				'action' => $action,
				'key' => $inherits ? null : $key,
				'type_key' => $typeChanged && $state->hasContent($model) ? 't:' . $key : null,
				'move_blocked' => $blocked,
				'label_kept' => $labelKept,
				// Typ ustawiony przez zespół wygrywa z propozycją analizy.
				'type_kept' => $model !== null && ! $typeChanged && $model->type->value !== $proposal['type'],
				'children' => $this->planFields(
					$proposal['children'],
					$state,
					$model ? ($blocked ? (int) $model->section_id : $sectionId) : null,
					$model?->id,
					$isRepeater ? ($model ? FigmaImportState::fieldKey($model) : $key) : $repeaterKey,
					in_array($action, ['create', 'restore'], true),
					$rebasedHere,
				),
			];
		}

		$this->markOrder($entries);

		return $entries;
	}

	/**
	 * Etykieta z pliku, której zespół nie zmieniał, idzie za Figmą.
	 */
	private function labelChanged(string $current, ?array $baseline, string $proposed): bool
	{
		return $baseline !== null && $baseline['label'] !== $proposed && $current === $baseline['label'];
	}

	private function labelKept(string $current, ?array $baseline, string $proposed): bool
	{
		return $baseline !== null && $baseline['label'] !== $proposed && $current !== $baseline['label'];
	}

	/**
	 * Oznacza elementy, które zmieniły kolejność względem rodzeństwa z ostatniego importu.
	 * Poza najdłuższym rosnącym podciągiem są tylko te, które naprawdę się przesunęły.
	 */
	private function markOrder(array &$entries): void
	{
		$positions = [];

		foreach ($entries as $index => $entry) {
			$position = $entry['model']?->figma_meta['proposed']['position'] ?? null;

			if ($position !== null && in_array($entry['action'], ['update', 'unchanged'], true) && ! in_array('move', $entry['changes'], true)) {
				$positions[$index] = (int) $position;
			}
		}

		$stable = $this->longestIncreasing($positions);

		foreach (array_diff_key($positions, $stable) as $index => $position) {
			$entries[$index]['changes'][] = 'order';
			$entries[$index]['action'] = 'update';
		}
	}

	/**
	 * @param array<int, int> $values
	 * @return array<int, int> wartości najdłuższego rosnącego podciągu, z zachowaniem kluczy
	 */
	private function longestIncreasing(array $values): array
	{
		$best = [];

		foreach ($values as $index => $value) {
			$chain = [];

			foreach ($best as $previous) {
				if (end($previous) < $value && count($previous) >= count($chain)) {
					$chain = $previous;
				}
			}

			$chain[$index] = $value;
			$best[$index] = $chain;
		}

		$longest = [];

		foreach ($best as $chain) {
			if (count($chain) > count($longest)) {
				$longest = $chain;
			}
		}

		return $longest;
	}

	/**
	 * Aktywne elementy z Figmy, których nie ma w propozycji → archiwum (lub zachowanie, gdy mają pola ręczne).
	 *
	 * Gdy drzewo frame'u jest przycięte (limit głębokości lub liczby warstw), brak warstwy nie dowodzi,
	 * że zniknęła z Figmy, a analiza mogła ją pominąć przez brak dzieci. Wtedy nic nie trafia do archiwum.
	 */
	private function planArchive(FigmaImportState $state, bool $truncated): array
	{
		$items = [];

		foreach ($state->unclaimedSections() as $section) {
			$manual = $state->manualFieldsIn($section);
			$items[] = ['kind' => 'section', 'model' => $section, 'manual' => $manual, 'unverified' => $truncated, 'key' => 'a:section:' . $section->id,
				'action' => $manual > 0 || $truncated ? 'keep' : 'archive'];
		}

		foreach ($state->unclaimedFields() as $field) {
			$manual = $state->manualDescendants($field);
			$items[] = ['kind' => 'field', 'model' => $field, 'manual' => $manual, 'unverified' => $truncated, 'key' => 'a:field:' . $field->id,
				'action' => $manual > 0 || $truncated ? 'keep' : 'archive'];
		}

		return $items;
	}

	private function isTruncated(array $node): bool
	{
		if (! empty($node['truncated'])) {
			return true;
		}

		foreach ((array) ($node['children'] ?? []) as $child) {
			if (is_array($child) && $this->isTruncated($child)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Klucze zmian do wyboru. Element bez własnego klucza idzie za rodzicem.
	 *
	 * @return array{changes: string[], risky: string[]}
	 */
	private function keys(array $entries): array
	{
		$keys = ['changes' => [], 'risky' => []];

		$walk = function (array $list) use (&$walk, &$keys) {
			foreach ($list as $entry) {
				if ($entry['key'] !== null && $entry['action'] !== 'unchanged') {
					$keys['changes'][] = $entry['key'];
				}

				if ($entry['type_key'] ?? null) {
					$keys['risky'][] = $entry['type_key'];
				}

				$walk($entry['children'] ?? $entry['fields']);
			}
		};

		$walk($entries);

		return $keys;
	}

	/**
	 * Czy element ma być zastosowany. „Bez zmian” odświeża tylko dane techniczne, więc nie wymaga wyboru.
	 */
	private function chosen(array $entry, array $selected, bool $inherited): bool
	{
		return match (true) {
			$entry['action'] === 'unchanged' => true,
			$entry['key'] === null => $inherited,
			default => isset($selected[$entry['key']]),
		};
	}

	/**
	 * Czy element ma trafić na nowe miejsce wśród rodzeństwa.
	 */
	private function placed(array $entry): bool
	{
		return in_array($entry['action'], ['create', 'restore'], true) || array_intersect(['move', 'order'], $entry['changes']) !== [];
	}

	/**
	 * Dane z Figmy z bazą porównania dla kolejnego importu.
	 */
	private function sourceMeta(array $proposal, string $label, string $type): array
	{
		return $proposal['meta'] + ['proposed' => ['label' => $label, 'type' => $type, 'position' => $proposal['position']]];
	}

	/**
	 * Porównanie luźne: MySQL sortuje klucze JSON, więc ścisłe porównanie Eloquenta widzi różnicę przy tych samych danych.
	 */
	private function sourceChanged(Section|Field $model, array $meta): bool
	{
		return $model->figma_meta != $meta;
	}

	private function applySection(Page $page, array $entry, bool $chosen): ?Section
	{
		$proposal = $entry['proposal'];
		$model = $entry['model'];

		if ($entry['action'] === 'skipped' || (! $model && ! $chosen)) {
			return null;
		}

		$meta = $this->sourceMeta($proposal, $proposal['name'], FieldType::Group->value);

		if (! $model) {
			return Section::create([
				'project_id' => $page->project_id,
				'page_id' => $page->id,
				'name' => $proposal['name'],
				'source' => ElementSource::Figma,
				'figma_node_id' => $proposal['node_id'],
				'figma_node_name' => $proposal['meta']['node_name'] ?? null,
				'figma_meta' => $meta,
				'position' => $proposal['position'],
			]);
		}

		if ($chosen && ($entry['action'] !== 'unchanged' || $this->sourceChanged($model, $meta))) {
			$model->forceFill(array_filter([
				'name' => in_array('label', $entry['changes'], true) ? $proposal['name'] : null,
			]) + [
				'figma_node_name' => $proposal['meta']['node_name'] ?? null,
				'figma_meta' => $meta,
				'archived_at' => null,
			])->save();
		}

		return $model;
	}

	private function applyFields(array $entries, Section $section, ?Field $parent, int $depth, array $selected, bool $inherited, Actor $actor): void
	{
		$sequence = [];

		foreach ($entries as $entry) {
			$proposal = $entry['proposal'];
			$model = $entry['model'];
			$chosen = $this->chosen($entry, $selected, $inherited);

			if (! $model && ! $chosen) {
				continue;
			}

			if (! $model) {
				$model = Field::create([
					'project_id' => $section->project_id,
					'section_id' => $section->id,
					'parent_id' => $parent?->id,
					'type' => $proposal['type'],
					'name' => $this->uniqueName($section, $parent, FrameAnalyzer::machineName($proposal['label'])),
					'label' => mb_substr($proposal['label'], 0, 190),
					'source' => ElementSource::Figma,
					'depth' => $depth,
					'figma_node_id' => $proposal['node_id'],
					'figma_text' => $proposal['figma_text'],
					'figma_meta' => $this->sourceMeta($proposal, $proposal['label'], $proposal['type']),
					'position' => $proposal['position'],
					'is_hidden' => $this->onlyLinks($proposal),
				]);
			} elseif ($chosen) {
				$this->updateField($model, $entry, $section, $parent, $depth, $selected, $actor);
			}

			if ((int) $model->section_id === (int) $section->id && $model->parent_id === $parent?->id) {
				$sequence[] = [$model, $chosen && $this->placed($entry)];
			}

			$home = (int) $model->section_id === (int) $section->id ? $section : Section::query()->findOrFail($model->section_id);

			$this->applyFields($entry['children'], $home, $model, (int) $model->depth + 1, $selected, $chosen, $actor);
		}

		$this->arrange(
			Field::query()->where('section_id', $section->id)->where('parent_id', $parent?->id)->where('source', ElementSource::Figma->value)->whereNull('archived_at')->orderBy('position')->orderBy('id')->get(),
			$sequence,
		);
	}

	private function updateField(Field $model, array $entry, Section $section, ?Field $parent, int $depth, array $selected, Actor $actor): void
	{
		$proposal = $entry['proposal'];
		$changes = $entry['changes'];
		$baseline = $model->figma_meta['proposed'] ?? null;
		// Ryzykowna zmiana typu ma osobny wybór: bez niego baza porównania zostaje i zmiana wróci w podglądzie.
		$typeApplied = in_array('type', $changes, true) && ($entry['type_key'] === null || isset($selected[$entry['type_key']]));
		$typeSkipped = in_array('type', $changes, true) && ! $typeApplied;
		$meta = $this->sourceMeta($proposal, $proposal['label'], $typeSkipped ? $baseline['type'] : $proposal['type']);

		if ($entry['action'] === 'unchanged' && ! $this->sourceChanged($model, $meta)) {
			return;
		}

		$attributes = [
			'figma_node_id' => $proposal['node_id'],
			'figma_text' => $proposal['figma_text'],
			'figma_meta' => $meta,
			'archived_at' => null,
		];

		if (in_array('label', $changes, true)) {
			$attributes['label'] = mb_substr($proposal['label'], 0, 190);
		}

		if ($typeApplied) {
			if ($entry['type_key'] !== null) {
				$this->content->clearForTypeChange($model, $actor);
			}

			$attributes['type'] = $proposal['type'];
		}

		if (in_array('move', $changes, true)) {
			$sectionChanged = (int) $model->section_id !== (int) $section->id;
			$depthDelta = $depth - (int) $model->depth;

			$attributes += ['section_id' => $section->id, 'parent_id' => $parent?->id, 'depth' => $depth];
		}

		$model->forceFill($attributes)->save();

		if (($sectionChanged ?? false) || ($depthDelta ?? 0) !== 0) {
			$this->moveDescendants($model, (int) $section->id, $depthDelta ?? 0);
		}
	}

	/**
	 * Wstawia elementy po ich poprzedniku z Figmy i rozdaje pozycje, które rodzeństwo już zajmuje,
	 * więc reszta zachowuje kolejność zespołu, a ręczne sąsiednie elementy nie są zapisywane.
	 *
	 * @param Collection<int, Section|Field> $siblings elementy Figmy w obecnej kolejności
	 * @param array<int, array{0: Section|Field, 1: bool}> $sequence elementy w kolejności Figmy, z flagą przestawienia
	 */
	private function arrange(Collection $siblings, array $sequence): void
	{
		if (! array_filter(array_column($sequence, 1))) {
			return;
		}

		$order = $siblings->modelKeys();
		$previous = null;

		foreach ($sequence as [$model, $place]) {
			if ($place) {
				$order = array_values(array_diff($order, [$model->id]));
				$after = $previous === null ? false : array_search($previous, $order, true);

				array_splice($order, $after === false ? 0 : $after + 1, 0, [$model->id]);
			}

			if (in_array($model->id, $order, true)) {
				$previous = $model->id;
			}
		}

		$slot = -1;
		$positions = $siblings->pluck('position')->sort()->values();
		$models = $siblings->keyBy('id');

		foreach ($order as $index => $id) {
			$slot = max((int) ($positions[$index] ?? 0), $slot + 1);
			$models[$id]->forceFill(['position' => $slot])->save();
		}
	}

	/**
	 * Potomkowie przeniesionego kontenera (tylko z Figmy, kontener z ręcznymi nie jest przenoszony) idą za nim.
	 */
	private function moveDescendants(Field $field, int $sectionId, int $depthDelta): void
	{
		foreach (Field::query()->where('parent_id', $field->id)->get() as $child) {
			$child->forceFill(['section_id' => $sectionId, 'depth' => max(0, (int) $child->depth + $depthDelta)])->save();
			$this->moveDescendants($child, $sectionId, $depthDelta);
		}
	}

	private function uniqueName(Section $section, ?Field $parent, string $base): string
	{
		$taken = Field::query()
			->where('section_id', $section->id)
			->where('parent_id', $parent?->id)
			->pluck('name')
			->all();

		$name = $base;

		for ($i = 2; in_array($name, $taken, true); $i++) {
			$name = mb_substr($base, 0, 55) . '_' . $i;
		}

		return $name;
	}

	/**
	 * @param ?array<string, int> $selected wybrane klucze; null liczy cały plan
	 */
	private function counts(array $sections, array $archive, ?array $selected = null): array
	{
		$counts = ['create' => 0, 'update' => 0, 'moved' => 0, 'restore' => 0, 'unchanged' => 0, 'archive' => 0, 'keep' => 0, 'skipped' => 0];

		$walk = function (array $entries, bool $inherited) use (&$walk, &$counts, $selected) {
			foreach ($entries as $entry) {
				$chosen = $selected === null || $this->chosen($entry, $selected, $inherited);

				if ($chosen) {
					$counts[$entry['action']]++;
					$counts['moved'] += $entry['action'] === 'update' && array_intersect(['move', 'order'], $entry['changes']) !== [] ? 1 : 0;
				}

				$walk($entry['children'] ?? $entry['fields'], $chosen);
			}
		};

		$walk($sections, true);

		foreach ($archive as $item) {
			if ($item['action'] !== 'archive' || $selected === null || isset($selected[$item['key']])) {
				$counts[$item['action']]++;
			}
		}

		return $counts;
	}

	/**
	 * Odcisk tego, co pracownik widzi w podglądzie: wersja frame'u, wynik analizy (z regułami)
	 * i aktualny stan sekcji oraz pól podstrony.
	 */
	private function fingerprint(FigmaFrame $frame, array $analysis, Collection $sections, Collection $fields, array $risky): string
	{
		// Pełne atrybuty wierszy: znacznik czasu ma rozdzielczość sekundy, więc sam `updated_at` nie wystarcza.
		$state = fn (Collection $models) => $models->map(fn ($model) => $model->getAttributes())->values()->all();

		return hash('sha256', json_encode([
			$frame->id,
			$frame->file_version,
			$frame->fetched_at?->getTimestamp(),
			$analysis['sections'],
			array_column($analysis['excluded'], 'node_id'),
			array_column($analysis['restored'], 'node_id'),
			$state($sections),
			$state($fields),
			// Treść wpisana po podglądzie zmienia zwykłą zmianę typu w ryzykowną.
			$risky,
		], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
	}

	private function findNode(array $node, string $id): ?array
	{
		if (($node['id'] ?? null) === $id) {
			return $node;
		}

		foreach ((array) ($node['children'] ?? []) as $child) {
			if (is_array($child) && ($found = $this->findNode($child, $id))) {
				return $found;
			}
		}

		return null;
	}
}
