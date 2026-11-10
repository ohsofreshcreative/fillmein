<?php

namespace App\Services;

use App\Enums\ElementSource;
use App\Exceptions\PageStructureException;
use App\Integrations\Figma\FigmaUrl;
use App\Models\Page;
use App\Models\Project;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use WP_User;

/**
 * Cykl życia podstron: tworzenie, edycja, hierarchia, kolejność, archiwizacja.
 *
 * Reguły pilnowane tutaj (autoryzację sprawdza wywołujący przez ProjectPolicy):
 * - slug jest unikalny w obrębie rodzica (indeks `fmi_pages_parent_slug_unique` jest ostatnią linią obrony),
 * - hierarchia nie może zawierać cykli ani przekraczać `fillmein.pages.max_depth`,
 * - rodzic musi należeć do tego samego projektu (ochrona przed IDOR przez podmianę `parent_id`),
 * - podstrony nie są usuwane fizycznie — dostają `archived_at` (treść zostaje).
 */
class PageService
{
	public function __construct(private ActivityLogger $activity)
	{
	}

	/**
	 * @param array{name: string, slug?: ?string, parent_id?: ?int, figma_frame_url?: ?string, client_can_duplicate?: bool} $data
	 */
	public function create(Project $project, array $data, WP_User $by): Page
	{
		$parent = $this->resolveParent($project, $data['parent_id'] ?? null);

		$this->assertDepth($parent);

		$frame = ($data['figma_frame_url'] ?? null) ?: null;

		return DB::transaction(function () use ($project, $data, $parent, $frame, $by) {
			$page = $project->pages()->create([
				'parent_id' => $parent?->id,
				'name' => $data['name'],
				'slug' => $this->uniqueSlug($project, $parent?->id, $data['slug'] ?? $data['name']),
				'source' => ElementSource::Manual,
				'figma_frame_url' => $frame,
				'figma_node_id' => $frame ? FigmaUrl::nodeId($frame) : null,
				'position' => $this->nextPosition($project, $parent?->id),
				'client_can_duplicate' => (bool) ($data['client_can_duplicate'] ?? false),
			]);

			$this->activity->log('page.created', Actor::user($by), $project, $page, [
				'page_name' => $page->name,
				'parent_id' => $page->parent_id,
			]);

			return $page;
		});
	}

	/**
	 * @param array{name?: string, slug?: ?string, parent_id?: ?int, figma_frame_url?: ?string, client_can_duplicate?: bool} $data
	 */
	public function update(Page $page, array $data, WP_User $by): Page
	{
		$project = $page->project;

		$parent = array_key_exists('parent_id', $data)
			? $this->resolveParent($project, $data['parent_id'])
			: $page->parent;

		if (($parent?->id ?? null) !== $page->parent_id) {
			$this->assertNoCycle($page, $parent);
			$this->assertDepth($parent, $this->subtreeHeight($page));
		}

		return DB::transaction(function () use ($page, $project, $data, $parent, $by) {
			$changed = [];
			$attributes = [];

			if (array_key_exists('name', $data) && $data['name'] !== $page->name) {
				$attributes['name'] = $data['name'];
				$changed[] = 'name';
			}

			$parentChanged = ($parent?->id ?? null) !== $page->parent_id;

			if ($parentChanged) {
				$attributes['parent_id'] = $parent?->id;
				$attributes['position'] = $this->nextPosition($project, $parent?->id);
				$changed[] = 'parent_id';
			}

			// Slug przeliczamy, gdy podano nowy albo gdy zmiana rodzica mogła wywołać kolizję.
			$slugBase = ($data['slug'] ?? null) ?: ($attributes['name'] ?? $page->name);
			$slug = $this->uniqueSlug($project, $parent?->id, $slugBase, $page->id);

			if ($slug !== $page->slug) {
				$attributes['slug'] = $slug;
				$changed[] = 'slug';
			}

			if (array_key_exists('figma_frame_url', $data)) {
				$frame = $data['figma_frame_url'] ?: null;

				if ($frame !== $page->figma_frame_url) {
					$attributes['figma_frame_url'] = $frame;
					$attributes['figma_node_id'] = $frame ? FigmaUrl::nodeId($frame) : null;
					$changed[] = 'figma_frame_url';
				}
			}

			if (array_key_exists('client_can_duplicate', $data)) {
				$flag = (bool) $data['client_can_duplicate'];

				if ($flag !== (bool) $page->client_can_duplicate) {
					$attributes['client_can_duplicate'] = $flag;
					$changed[] = 'client_can_duplicate';
				}
			}

			if ($attributes !== []) {
				$page->update($attributes);
			}

			if ($changed !== []) {
				$this->activity->log('page.updated', Actor::user($by), $project, $page, [
					'page_name' => $page->name,
					'changed' => $changed,
				]);
			}

			return $page->refresh();
		});
	}

	/**
	 * Archiwizacja podstrony i całego jej poddrzewa. Treść i definicje zostają w bazie.
	 */
	public function archive(Page $page, WP_User $by): void
	{
		if ($page->isArchived()) {
			return;
		}

		DB::transaction(function () use ($page) {
			$ids = $this->subtreeIds($page);

			Page::query()->whereIn('id', $ids)->whereNull('archived_at')->update([
				'archived_at' => now(),
				'updated_at' => now(),
			]);
		});

		$this->activity->log('page.archived', Actor::user($by), $page->project, $page, [
			'page_name' => $page->name,
		]);

		$page->refresh();
	}

	/**
	 * Przywrócenie podstrony. Rodzic musi być aktywny, żeby drzewo pozostało spójne.
	 */
	public function restore(Page $page, WP_User $by): void
	{
		if (! $page->isArchived()) {
			return;
		}

		if ($page->parent && $page->parent->isArchived()) {
			throw new PageStructureException('Najpierw przywróć podstronę nadrzędną.');
		}

		$page->update(['archived_at' => null]);

		$this->activity->log('page.restored', Actor::user($by), $page->project, $page, [
			'page_name' => $page->name,
		]);
	}

	/**
	 * Zmiana kolejności rodzeństwa. `$orderedIds` to identyfikatory podstron o tym samym rodzicu.
	 *
	 * @param int[] $orderedIds
	 */
	public function reorder(Project $project, ?int $parentId, array $orderedIds, WP_User $by): void
	{
		$siblings = Page::query()
			->where('project_id', $project->id)
			->where('parent_key', (int) ($parentId ?? 0))
			->pluck('id')
			->map(fn ($id) => (int) $id);

		$target = collect($orderedIds)->map(fn ($id) => (int) $id)->unique()->values();

		if ($target->diff($siblings)->isNotEmpty()) {
			throw new PageStructureException('Lista kolejności zawiera podstronę z innego poziomu lub projektu.', 'order');
		}

		DB::transaction(function () use ($target, $siblings, $project) {
			$position = 0;

			foreach ($target as $id) {
				Page::query()->whereKey($id)->where('project_id', $project->id)->update(['position' => $position++]);
			}

			// Rodzeństwo pominięte w żądaniu trafia na koniec, zachowując względną kolejność.
			foreach ($siblings->diff($target) as $id) {
				Page::query()->whereKey($id)->where('project_id', $project->id)->update(['position' => $position++]);
			}
		});

		$this->activity->log('page.reordered', Actor::user($by), $project, null, [
			'parent_id' => $parentId,
			'order' => $target->all(),
		]);
	}

	/**
	 * Slug unikalny w obrębie rodzica. `$ignoreId` pozwala zapisać podstronę bez kolizji z sobą samą.
	 */
	public function uniqueSlug(Project $project, ?int $parentId, string $base, ?int $ignoreId = null): string
	{
		$slug = Str::slug($base) ?: 'podstrona';
		$slug = Str::limit($slug, 180, '');
		$candidate = $slug;
		$suffix = 2;

		while ($this->slugTaken($project, $parentId, $candidate, $ignoreId)) {
			$candidate = Str::limit($slug, 180 - strlen((string) $suffix) - 1, '') . '-' . $suffix;
			$suffix++;
		}

		return $candidate;
	}

	private function slugTaken(Project $project, ?int $parentId, string $slug, ?int $ignoreId): bool
	{
		return Page::query()
			->where('project_id', $project->id)
			->where('parent_key', (int) ($parentId ?? 0))
			->where('slug', $slug)
			->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
			->exists();
	}

	private function nextPosition(Project $project, ?int $parentId): int
	{
		return (int) Page::query()
			->where('project_id', $project->id)
			->where('parent_key', (int) ($parentId ?? 0))
			->max('position') + 1;
	}

	/**
	 * Rodzic z zakresu projektu. Nigdy nie pobieramy go po samym ID z żądania.
	 */
	private function resolveParent(Project $project, ?int $parentId): ?Page
	{
		if (! $parentId) {
			return null;
		}

		$parent = Page::query()
			->where('project_id', $project->id)
			->whereKey($parentId)
			->first();

		if (! $parent) {
			throw new PageStructureException('Wybrana podstrona nadrzędna nie należy do tego projektu.');
		}

		if ($parent->isArchived()) {
			throw new PageStructureException('Nie można podpiąć podstrony pod zarchiwizowaną podstronę.');
		}

		return $parent;
	}

	/**
	 * Podstrona nie może stać się własnym potomkiem (ani swoim rodzicem).
	 */
	public function assertNoCycle(Page $page, ?Page $parent): void
	{
		if (! $parent) {
			return;
		}

		if ($parent->id === $page->id) {
			throw new PageStructureException('Podstrona nie może być swoją własną podstroną nadrzędną.');
		}

		if (in_array((int) $parent->id, $this->subtreeIds($page), true)) {
			throw new PageStructureException('Nie można przenieść podstrony do jej własnej podstrony podrzędnej.');
		}
	}

	public function assertDepth(?Page $parent, int $extraHeight = 0): void
	{
		$max = (int) config('fillmein.pages.max_depth', 3);
		$depth = $parent ? $parent->depth() + 1 : 0;

		if ($depth + $extraHeight > $max) {
			throw new PageStructureException(sprintf('Maksymalny poziom zagnieżdżenia podstron to %d.', $max));
		}
	}

	/**
	 * Identyfikatory podstrony i wszystkich jej potomków (iteracyjnie, bez rekurencji w SQL).
	 *
	 * @return int[]
	 */
	public function subtreeIds(Page $page): array
	{
		$ids = [(int) $page->id];
		$frontier = $ids;
		$guard = 0;

		while ($frontier !== [] && $guard++ < 50) {
			$frontier = Page::query()
				->where('project_id', $page->project_id)
				->whereIn('parent_id', $frontier)
				->whereNotIn('id', $ids)
				->pluck('id')
				->map(fn ($id) => (int) $id)
				->all();

			$ids = array_merge($ids, $frontier);
		}

		return $ids;
	}

	/**
	 * Wysokość poddrzewa (0 = sama podstrona bez dzieci) — potrzebna przy sprawdzaniu limitu zagnieżdżenia.
	 * `$activeOnly`: tylko niezarchiwizowani potomkowie (tak kopiuje duplikowanie).
	 */
	public function subtreeHeight(Page $page, bool $activeOnly = false): int
	{
		$height = 0;
		$frontier = [(int) $page->id];
		$guard = 0;

		while ($frontier !== [] && $guard++ < 50) {
			$frontier = Page::query()
				->where('project_id', $page->project_id)
				->whereIn('parent_id', $frontier)
				->when($activeOnly, fn ($query) => $query->whereNull('archived_at'))
				->pluck('id')
				->map(fn ($id) => (int) $id)
				->all();

			if ($frontier !== []) {
				$height++;
			}
		}

		return $height;
	}
}
