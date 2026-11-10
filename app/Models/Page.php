<?php

namespace App\Models;

use App\Enums\ElementSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;

/**
 * Podstrona projektu. Każda ma własny, opcjonalny link do JEDNEGO frame'u Figmy
 * (`figma_frame_url`) — plik Figmy projektu jest tylko kontekstem nadrzędnym.
 *
 * Hierarchia rodzic–dziecko: `parent_id` (NULL = korzeń). Kolumna `parent_key` jest
 * technicznym odbiciem `parent_id` (0 dla korzenia) i służy wyłącznie indeksowi unikalnemu
 * slugów w obrębie rodzica — MySQL dopuszcza wiele NULL w indeksie unikalnym.
 * Zapisy prowadzi App\Services\PageService (slugi, kolejność, brak cykli).
 */
class Page extends Model
{
	use HasUuids;

	protected $table = 'fmi_pages';

	protected $fillable = [
		'project_id',
		'parent_id',
		'name',
		'slug',
		'source',
		'figma_node_id',
		'figma_node_name',
		'figma_frame_url',
		'position',
		'client_can_duplicate',
		'duplicated_from_id',
		'archived_at',
	];

	protected $attributes = [
		'source' => 'figma',
		'client_can_duplicate' => false,
	];

	protected function casts(): array
	{
		return [
			'source' => ElementSource::class,
			'position' => 'integer',
			'parent_id' => 'integer',
			'parent_key' => 'integer',
			'duplicated_from_id' => 'integer',
			'client_can_duplicate' => 'boolean',
			'archived_at' => 'datetime',
		];
	}

	public function uniqueIds(): array
	{
		return ['uuid'];
	}

	/**
	 * `parent_key` nigdy nie jest ustawiany z zewnątrz — wyprowadzamy go z `parent_id`,
	 * żeby indeks unikalny slugów był spójny niezależnie od tego, kto zapisuje model.
	 */
	protected static function booted(): void
	{
		static::saving(function (self $page) {
			$page->setAttribute('parent_key', (int) ($page->parent_id ?? 0));
		});
	}

	public function project(): BelongsTo
	{
		return $this->belongsTo(Project::class);
	}

	public function parent(): BelongsTo
	{
		return $this->belongsTo(self::class, 'parent_id');
	}

	public function children(): HasMany
	{
		return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
	}

	public function duplicatedFrom(): BelongsTo
	{
		return $this->belongsTo(self::class, 'duplicated_from_id');
	}

	public function duplicates(): HasMany
	{
		return $this->hasMany(self::class, 'duplicated_from_id');
	}

	public function sections(): HasMany
	{
		return $this->hasMany(Section::class)->orderBy('position');
	}

	/**
	 * Wszystkie pola podstrony (także potomne); używane m.in. do wiązania `{field}` w zakresie podstrony.
	 */
	public function fields(): HasManyThrough
	{
		return $this->hasManyThrough(Field::class, Section::class);
	}

	public function mapping(): MorphOne
	{
		return $this->morphOne(WpMapping::class, 'mappable');
	}

	public function scopeActive(Builder $query): Builder
	{
		return $query->whereNull('archived_at');
	}

	public function scopeArchived(Builder $query): Builder
	{
		return $query->whereNotNull('archived_at');
	}

	public function scopeRoots(Builder $query): Builder
	{
		return $query->whereNull('parent_id');
	}

	public function isArchived(): bool
	{
		return $this->archived_at !== null;
	}

	/**
	 * Przodkowie od najbliższego rodzica do korzenia. Pętla jest zabezpieczona licznikiem,
	 * bo dane mogłyby zostać uszkodzone poza aplikacją.
	 */
	public function ancestors(): Collection
	{
		$ancestors = new Collection();
		$parent = $this->parent;
		$guard = 0;

		while ($parent && $guard++ < 50) {
			$ancestors->push($parent);
			$parent = $parent->parent;
		}

		return $ancestors;
	}

	public function depth(): int
	{
		return $this->ancestors()->count();
	}

	/**
	 * Drzewo podstron projektu zbudowane w pamięci (jedno zapytanie, bez N+1).
	 * Przyjmuje dowolną kolekcję podstron — także już przefiltrowaną.
	 *
	 * @param iterable<self> $pages
	 * @return array<int, array{page: self, children: array}>
	 */
	public static function tree(iterable $pages): array
	{
		$byParent = [];

		foreach ($pages as $page) {
			$byParent[(int) ($page->parent_id ?? 0)][] = $page;
		}

		$build = function (int $parentKey) use (&$build, $byParent): array {
			return array_map(fn (self $page) => [
				'page' => $page,
				'children' => $build((int) $page->id),
			], $byParent[$parentKey] ?? []);
		};

		return $build(0);
	}
}
