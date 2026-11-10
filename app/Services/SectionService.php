<?php

namespace App\Services;

use App\Enums\ElementSource;
use App\Enums\FieldType;
use App\Exceptions\FieldConfigException;
use App\Integrations\Figma\FrameAnalyzer;
use App\Models\Field;
use App\Models\FieldValue;
use App\Models\Page;
use App\Models\Section;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use WP_User;

/**
 * Ręczna konfiguracja sekcji i pól podstrony: tworzenie, edycja, kolejność, archiwizacja.
 *
 * - Elementy tworzone tutaj mają `source = manual` — import z Figmy ich nie zmienia ani nie archiwizuje.
 * - Elementy z Figmy można edytować; import zachowuje te ustawienia i aktualizuje tylko dane źródłowe.
 * - Sekcje i pola nie są usuwane fizycznie — `archived_at` (treść zostaje). Archiwizacja kontenera nie
 *   dotyka potomków: znikają razem z nim, a po przywróceniu wracają w tym samym stanie.
 * - Nazwa techniczna jest unikalna wśród rodzeństwa łącznie z archiwum, żeby przywrócenie nie dało kolizji.
 * - Limit zagnieżdżenia: `fillmein.fields.max_depth`. Kontener na ostatnim poziomie nie mógłby mieć pól,
 *   więc jest odrzucany.
 *
 * Autoryzację (ProjectPolicy@configureFields) sprawdza wywołujący; przynależność sekcji i pola do podstrony
 * zapewnia wiązanie trasy w zakresie podstrony, a pola nadrzędnego i list kolejności — ten serwis.
 */
class SectionService
{
	public function __construct(
		private ActivityLogger $activity,
		private ContentService $content,
	) {
	}

	/**
	 * @param array{name: string, is_hidden?: bool} $data
	 */
	public function create(Page $page, array $data, WP_User $by): Section
	{
		return DB::transaction(function () use ($page, $data, $by) {
			$section = Section::create([
				'project_id' => $page->project_id,
				'page_id' => $page->id,
				'name' => $data['name'],
				'source' => ElementSource::Manual,
				'is_hidden' => (bool) ($data['is_hidden'] ?? false),
				'position' => (int) Section::query()->where('page_id', $page->id)->max('position') + 1,
			]);

			$this->activity->log('section.created', Actor::user($by), $page->project, $section, [
				'page_name' => $page->name,
				'section_name' => $section->name,
			]);

			return $section;
		});
	}

	/**
	 * @param array{name?: string, is_hidden?: bool} $data
	 */
	public function update(Section $section, array $data, WP_User $by): Section
	{
		$attributes = [];

		if (array_key_exists('name', $data) && $data['name'] !== $section->name) {
			$attributes['name'] = $data['name'];
		}

		if (array_key_exists('is_hidden', $data) && (bool) $data['is_hidden'] !== $section->is_hidden) {
			$attributes['is_hidden'] = (bool) $data['is_hidden'];
		}

		if ($attributes === []) {
			return $section;
		}

		$section->update($attributes);

		$this->activity->log('section.updated', Actor::user($by), $section->page->project, $section, [
			'page_name' => $section->page->name,
			'section_name' => $section->name,
			'changed' => array_keys($attributes),
		]);

		return $section;
	}

	public function archive(Section $section, WP_User $by): void
	{
		if ($section->archived_at) {
			return;
		}

		$section->update(['archived_at' => now()]);

		$this->activity->log('section.archived', Actor::user($by), $section->page->project, $section, [
			'page_name' => $section->page->name,
			'section_name' => $section->name,
		]);
	}

	public function restore(Section $section, WP_User $by): void
	{
		if (! $section->archived_at) {
			return;
		}

		$section->update(['archived_at' => null]);

		$this->activity->log('section.restored', Actor::user($by), $section->page->project, $section, [
			'page_name' => $section->page->name,
			'section_name' => $section->name,
		]);
	}

	/**
	 * Kolejność sekcji podstrony. Sekcje pominięte w żądaniu trafiają na koniec (zachowując względną kolejność).
	 *
	 * @param int[] $orderedIds
	 */
	public function reorder(Page $page, array $orderedIds, WP_User $by): void
	{
		$siblings = Section::query()
			->where('page_id', $page->id)
			->orderBy('position')
			->orderBy('id')
			->pluck('id')
			->map(fn ($id) => (int) $id);

		$target = collect($orderedIds)->map(fn ($id) => (int) $id)->unique()->values();

		if ($target->diff($siblings)->isNotEmpty()) {
			throw new FieldConfigException('Lista kolejności zawiera sekcję z innej podstrony.', 'order');
		}

		DB::transaction(function () use ($page, $target, $siblings) {
			$position = 0;

			foreach ($target->concat($siblings->diff($target)) as $id) {
				Section::query()->whereKey($id)->where('page_id', $page->id)->update(['position' => $position++]);
			}
		});

		$this->activity->log('section.reordered', Actor::user($by), $page->project, $page, [
			'page_name' => $page->name,
			'order' => $target->all(),
		]);
	}

	/**
	 * @param array{type: string, label: string, name?: ?string, parent_id?: ?int, is_required?: bool, is_hidden?: bool, instructions?: ?string, settings?: array} $data
	 */
	public function createField(Section $section, array $data, WP_User $by): Field
	{
		return DB::transaction(function () use ($section, $data, $by) {
			$this->lockSection($section);

			if ($section->isArchived()) {
				throw new FieldConfigException('Nie można dodawać pól do sekcji z archiwum.', 'section');
			}

			$type = $this->resolveType($data['type'] ?? null);
			$parent = $this->resolveParent($section, $data['parent_id'] ?? null);
			$depth = $parent ? $parent->depth + 1 : 0;

			$this->assertDepthAllows($type, $depth);

			$field = Field::create([
				'project_id' => $section->project_id,
				'section_id' => $section->id,
				'parent_id' => $parent?->id,
				'type' => $type,
				'name' => $this->resolveName($section, $parent, $data['name'] ?? null, $data['label']),
				'label' => $data['label'],
				'source' => ElementSource::Manual,
				'depth' => $depth,
				'is_required' => (bool) ($data['is_required'] ?? false),
				'is_hidden' => (bool) ($data['is_hidden'] ?? false),
				'instructions' => $data['instructions'] ?? null,
				'settings' => $this->normalizeSettings($type, (array) ($data['settings'] ?? [])),
				'position' => (int) $this->siblings($section->id, $parent?->id)->max('position') + 1,
			]);

			$this->logField('field.created', $field, $by, ['type' => $type->value]);

			return $field;
		});
	}

	/**
	 * Zmiana typu niezachowująca treści (FieldType::keepsValueAs) wymaga `confirm_type_change`:
	 * bez tego FieldConfigException z `requiresConfirmation`, a z nim — czyszczenie przez ContentService
	 * (wartości zostają w historii rewizji).
	 *
	 * @param array{type?: string, label?: string, name?: ?string, is_required?: bool, is_hidden?: bool, instructions?: ?string, settings?: array, confirm_type_change?: bool} $data
	 */
	public function updateField(Field $field, array $data, WP_User $by): Field
	{
		return DB::transaction(function () use ($field, $data, $by) {
			$this->lockSection($field->section);

			$type = array_key_exists('type', $data) ? $this->resolveType($data['type']) : $field->type;
			$typeChanged = $type !== $field->type;
			$attributes = [];
			$cleared = 0;

			if ($typeChanged) {
				$this->assertTypeChangeAllowed($field, $type);
				$cleared = $this->applyTypeChange($field, $type, (bool) ($data['confirm_type_change'] ?? false), $by);
				$attributes['type'] = $type;
			}

			foreach (['label', 'instructions'] as $key) {
				if (array_key_exists($key, $data) && $data[$key] !== $field->{$key}) {
					$attributes[$key] = $data[$key];
				}
			}

			foreach (['is_required', 'is_hidden'] as $key) {
				if (array_key_exists($key, $data) && (bool) $data[$key] !== $field->{$key}) {
					$attributes[$key] = (bool) $data[$key];
				}
			}

			$name = $data['name'] ?? null;

			if ($name !== null && $name !== '' && $name !== $field->name) {
				$this->assertNameFree($field->section_id, $field->parent_id, $name);
				$attributes['name'] = $name;
			}

			if ($typeChanged || array_key_exists('settings', $data)) {
				$settings = $this->normalizeSettings($type, (array) ($data['settings'] ?? []));

				if ($settings != $field->settings) {
					$attributes['settings'] = $settings;
				}
			}

			if ($attributes === []) {
				return $field;
			}

			$field->update($attributes);

			$this->logField('field.updated', $field, $by, ['changed' => array_keys($attributes), 'values_cleared' => $cleared]);

			return $field;
		});
	}

	public function archiveField(Field $field, WP_User $by): void
	{
		if ($field->isArchived()) {
			return;
		}

		$field->update(['archived_at' => now()]);

		$this->logField('field.archived', $field, $by);
	}

	public function restoreField(Field $field, WP_User $by): void
	{
		if (! $field->isArchived()) {
			return;
		}

		if ($field->section->isArchived()) {
			throw new FieldConfigException('Najpierw przywróć sekcję tego pola.', 'restore');
		}

		if ($field->parent?->isArchived()) {
			throw new FieldConfigException('Najpierw przywróć pole nadrzędne.', 'restore');
		}

		$field->update(['archived_at' => null]);

		$this->logField('field.restored', $field, $by);
	}

	/**
	 * Kolejność rodzeństwa pól (poziom sekcji albo dzieci kontenera). Pominięte pola trafiają na koniec.
	 *
	 * @param int[] $orderedIds
	 */
	public function reorderFields(Section $section, ?int $parentId, array $orderedIds, WP_User $by): void
	{
		$parent = $this->resolveParent($section, $parentId, false);

		$siblings = $this->siblings($section->id, $parent?->id)
			->orderBy('position')
			->orderBy('id')
			->pluck('id')
			->map(fn ($id) => (int) $id);

		$target = collect($orderedIds)->map(fn ($id) => (int) $id)->unique()->values();

		if ($target->diff($siblings)->isNotEmpty()) {
			throw new FieldConfigException('Lista kolejności zawiera pole spoza tego poziomu.', 'order');
		}

		DB::transaction(function () use ($section, $parent, $target, $siblings) {
			$position = 0;

			foreach ($target->concat($siblings->diff($target)) as $id) {
				$this->siblings($section->id, $parent?->id)->whereKey($id)->update(['position' => $position++]);
			}
		});

		$this->activity->log('field.reordered', Actor::user($by), $section->page->project, $section, [
			'page_name' => $section->page->name,
			'section_name' => $section->name,
			'order' => $target->all(),
		]);
	}

	/** Blokada wiersza sekcji: równoległe zapisy nie przydzielą tej samej nazwy ani pozycji. */
	private function lockSection(Section $section): void
	{
		Section::query()->whereKey($section->id)->lockForUpdate()->first();
	}

	private function siblings(int $sectionId, ?int $parentId)
	{
		return Field::query()->where('section_id', $sectionId)->where('parent_id', $parentId);
	}

	private function resolveType(mixed $type): FieldType
	{
		$resolved = $type instanceof FieldType ? $type : FieldType::tryFrom((string) $type);

		return $resolved ?? throw new FieldConfigException('Nieobsługiwany typ pola.');
	}

	private function resolveParent(Section $section, ?int $parentId, bool $forNewField = true): ?Field
	{
		if (! $parentId) {
			return null;
		}

		$parent = Field::query()->where('section_id', $section->id)->whereKey($parentId)->first();

		if (! $parent) {
			throw new FieldConfigException('Pole nadrzędne nie należy do tej sekcji.', 'parent_id');
		}

		if (! $parent->type->isContainer()) {
			throw new FieldConfigException('Pola podrzędne może mieć tylko grupa lub repeater.', 'parent_id');
		}

		if ($forNewField && $parent->isArchived()) {
			throw new FieldConfigException('Nie można dodawać pól do elementu z archiwum.', 'parent_id');
		}

		return $parent;
	}

	private function assertDepthAllows(FieldType $type, int $depth): void
	{
		$max = (int) config('fillmein.fields.max_depth', 3);

		if ($depth > $max) {
			throw new FieldConfigException(sprintf('Przekroczono limit zagnieżdżenia (%d poziomy pod polem sekcji).', $max), 'parent_id');
		}

		if ($type->isContainer() && $depth === $max) {
			throw new FieldConfigException('Na tym poziomie zagnieżdżenia nie można dodać grupy ani repeatera.');
		}
	}

	private function resolveName(Section $section, ?Field $parent, ?string $name, string $label): string
	{
		if ($name !== null && $name !== '') {
			$this->assertNameFree($section->id, $parent?->id, $name);

			return $name;
		}

		$base = FrameAnalyzer::machineName($label);
		$taken = $this->siblings($section->id, $parent?->id)->pluck('name')->all();
		$candidate = $base;

		for ($i = 2; in_array($candidate, $taken, true); $i++) {
			$candidate = mb_substr($base, 0, 55) . '_' . $i;
		}

		return $candidate;
	}

	private function assertNameFree(int $sectionId, ?int $parentId, string $name): void
	{
		if ($this->siblings($sectionId, $parentId)->where('name', $name)->exists()) {
			throw new FieldConfigException('Ten klucz jest już zajęty na tym poziomie (także przez element z archiwum).', 'name');
		}
	}

	private function assertTypeChangeAllowed(Field $field, FieldType $type): void
	{
		// Także archiwalne dzieci: po przywróceniu wisiałyby pod polem, które nie może ich mieć,
		// a wartości w repeaterze są przypisane do wierszy, których grupa nie ma.
		if ($field->type->isContainer() && Field::query()->where('parent_id', $field->id)->exists()) {
			throw new FieldConfigException('Nie można zmienić typu kontenera, który ma pola podrzędne (także w archiwum).');
		}

		$this->assertDepthAllows($type, $field->depth);
	}

	/**
	 * @return int liczba wyczyszczonych wartości
	 */
	private function applyTypeChange(Field $field, FieldType $type, bool $confirmed, WP_User $by): int
	{
		$cleared = 0;

		if (! $field->type->keepsValueAs($type) && $this->hasContent($field)) {
			if (! $confirmed) {
				throw new FieldConfigException(
					'Ta zmiana typu usunie zapisaną treść pola. Zostanie ona tylko w historii zmian. Potwierdź, jeśli chcesz kontynuować.',
					'type',
					true,
				);
			}

			$cleared = $this->content->clearForTypeChange($field, Actor::user($by));
		}

		if ($field->type === FieldType::Repeater) {
			$field->rows()->delete(); // miękko: wiersze bez pól podrzędnych nie mają treści
		}

		return $cleared;
	}

	private function hasContent(Field $field): bool
	{
		return FieldValue::query()
			->where('field_id', $field->id)
			->pluck('value')
			->contains(fn ($value) => ContentService::hasContent($value));
	}

	/**
	 * Zostają tylko ustawienia danego typu (FieldType::settingKeys); puste pola formularza znikają.
	 */
	private function normalizeSettings(FieldType $type, array $input): ?array
	{
		$settings = [];

		foreach ($type->settingKeys() as $key) {
			$value = $input[$key] ?? null;

			if ($value !== null && $value !== '') {
				$settings[$key] = $key === 'placeholder' ? (string) $value : (int) $value;
			}
		}

		$limit = (int) config('fillmein.fields.max_items', 100);

		if (($settings['max_items'] ?? 0) > $limit || ($settings['min_items'] ?? 0) > $limit) {
			throw new FieldConfigException(sprintf('Limit elementów nie może przekraczać %d.', $limit), 'settings.max_items');
		}

		if (isset($settings['min_items'], $settings['max_items']) && $settings['min_items'] > $settings['max_items']) {
			throw new FieldConfigException('Minimalna liczba elementów nie może być większa niż maksymalna.', 'settings.max_items');
		}

		return $settings === [] ? null : $settings;
	}

	private function logField(string $event, Field $field, WP_User $by, array $properties = []): void
	{
		$page = $field->section->page;

		$this->activity->log($event, Actor::user($by), $page->project, $field, [
			'page_name' => $page->name,
			'section_name' => $field->section->name,
			'field_label' => $field->label,
		] + $properties);
	}
}
