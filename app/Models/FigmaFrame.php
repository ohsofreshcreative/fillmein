<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cache metadanych jednego frame'u Figmy w projekcie. Zapisy: App\Services\FigmaFrameService.
 */
class FigmaFrame extends Model
{
	protected $table = 'fmi_figma_frames';

	protected $fillable = [
		'project_id',
		'file_key',
		'node_id',
		'node_name',
		'node_type',
		'file_name',
		'file_version',
		'file_last_modified',
		'width',
		'height',
		'nodes_count',
		'document',
		'fetched_at',
	];

	protected function casts(): array
	{
		return [
			'document' => 'array',
			'width' => 'integer',
			'height' => 'integer',
			'nodes_count' => 'integer',
			'file_last_modified' => 'datetime',
			'fetched_at' => 'datetime',
		];
	}

	public function project(): BelongsTo
	{
		return $this->belongsTo(Project::class);
	}

	public function previews(): HasMany
	{
		return $this->hasMany(FigmaPreview::class, 'frame_id')->orderBy('position')->orderBy('id');
	}

	public function isFresh(): bool
	{
		return $this->fetched_at !== null
			&& $this->fetched_at->gt(now()->subMinutes((int) config('fillmein.figma.cache_ttl_minutes', 60)));
	}

	/**
	 * Bezpośrednie dzieci frame'u z przyciętego drzewa (kandydaci na sekcje).
	 *
	 * @return array<int, array>
	 */
	public function children(): array
	{
		return array_values(array_filter((array) ($this->document['children'] ?? []), 'is_array'));
	}
}
