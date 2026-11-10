<?php

namespace App\Models;

use App\Enums\ElementSource;
use App\Enums\FieldType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Definicja pola treści. Wartości są w FieldValue — konfigurację można zmieniać bez utraty treści.
 * Pola nie są usuwane fizycznie przez aplikację; zamiast tego dostają `archived_at`.
 *
 * Hierarchia: sekcja → pola (depth 0) → pola potomne grup/repeaterów (depth 1…max_depth).
 * Pochodzenie: ElementSource — import z Figmy nie usuwa ani nie nadpisuje pól `manual`.
 */
class Field extends Model
{
	use HasUuids;

	protected $table = 'fmi_fields';

	protected $fillable = [
		'project_id',
		'section_id',
		'parent_id',
		'type',
		'name',
		'label',
		'source',
		'depth',
		'is_required',
		'is_hidden',
		'instructions',
		'figma_node_id',
		'figma_text',
		'figma_meta',
		'settings',
		'position',
		'archived_at',
	];

	protected $attributes = [
		'source' => 'figma',
		'depth' => 0,
		'is_required' => false,
		'is_hidden' => false,
	];

	protected function casts(): array
	{
		return [
			'type' => FieldType::class,
			'source' => ElementSource::class,
			'depth' => 'integer',
			'is_required' => 'boolean',
			'is_hidden' => 'boolean',
			'settings' => 'array',
			'figma_meta' => 'array',
			'position' => 'integer',
			'archived_at' => 'datetime',
		];
	}

	public function uniqueIds(): array
	{
		return ['uuid'];
	}

	public function project(): BelongsTo
	{
		return $this->belongsTo(Project::class);
	}

	public function section(): BelongsTo
	{
		return $this->belongsTo(Section::class);
	}

	public function parent(): BelongsTo
	{
		return $this->belongsTo(self::class, 'parent_id');
	}

	public function children(): HasMany
	{
		return $this->hasMany(self::class, 'parent_id')->orderBy('position');
	}

	/**
	 * Elementy repeatera (tylko dla typu repeater).
	 */
	public function rows(): HasMany
	{
		return $this->hasMany(RepeaterRow::class)->orderBy('position');
	}

	public function assets(): HasMany
	{
		return $this->hasMany(Asset::class);
	}

	public function values(): HasMany
	{
		return $this->hasMany(FieldValue::class);
	}

	public function mapping(): MorphOne
	{
		return $this->morphOne(WpMapping::class, 'mappable');
	}

	public function scopeActive(Builder $query): Builder
	{
		return $query->whereNull('archived_at');
	}

	public function scopeFromFigma(Builder $query): Builder
	{
		return $query->where('source', ElementSource::Figma->value);
	}

	public function isArchived(): bool
	{
		return $this->archived_at !== null;
	}

	/**
	 * Czy w tym polu można zagnieździć kolejne (kontener i limit głębokości).
	 */
	public function canHaveChildren(): bool
	{
		return $this->type->isContainer() && $this->depth < (int) config('fillmein.fields.max_depth', 3);
	}

	/**
	 * Najbliższy repeater nadrzędny — wyznacza, do którego wiersza należy wartość pola.
	 */
	public function closestRepeater(): ?self
	{
		$parent = $this->parent;

		while ($parent && $parent->type !== FieldType::Repeater) {
			$parent = $parent->parent;
		}

		return $parent;
	}
}
