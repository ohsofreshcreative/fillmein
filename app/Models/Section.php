<?php

namespace App\Models;

use App\Enums\ElementSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Sekcja podstrony z podglądem z Figmy. Pochodzenie: ElementSource (figma / manual).
 */
class Section extends Model
{
	use HasUuids;

	protected $table = 'fmi_sections';

	protected $fillable = [
		'project_id',
		'page_id',
		'name',
		'source',
		'figma_node_id',
		'figma_node_name',
		'figma_meta',
		'position',
		'preview_path',
		'preview_width',
		'preview_height',
		'preview_updated_at',
		'is_hidden',
		'archived_at',
	];

	protected $attributes = [
		'source' => 'figma',
		'is_hidden' => false,
	];

	protected function casts(): array
	{
		return [
			'source' => ElementSource::class,
			'figma_meta' => 'array',
			'is_hidden' => 'boolean',
			'position' => 'integer',
			'preview_width' => 'integer',
			'preview_height' => 'integer',
			'preview_updated_at' => 'datetime',
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

	public function page(): BelongsTo
	{
		return $this->belongsTo(Page::class);
	}

	/**
	 * Pola najwyższego poziomu (bez potomków grup/repeaterów).
	 */
	public function fields(): HasMany
	{
		return $this->hasMany(Field::class)->whereNull('parent_id')->orderBy('position');
	}

	public function isArchived(): bool
	{
		return $this->archived_at !== null;
	}

	/**
	 * Sekcje zarządzane przez import z Figmy (ręczne są przez import pomijane).
	 */
	public function scopeFromFigma(Builder $query): Builder
	{
		return $query->where('source', ElementSource::Figma->value);
	}

	public function mapping(): MorphOne
	{
		return $this->morphOne(WpMapping::class, 'mappable');
	}
}
