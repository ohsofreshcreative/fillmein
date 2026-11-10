<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ekran rewizji: frame Figmy z potwierdzonymi wymiarami. Zapisy: App\Services\ReviewScreenService.
 *
 * Układ odniesienia pinezek (`PIN_REFERENCE`): x, y w zakresie 0–1 względem granic frame'u (absoluteBoundingBox),
 * (0,0) — lewy górny róg. Obowiązuje tylko przy `surface_confirmed` (obraz frame'u = jego granice).
 */
class ReviewScreen extends Model
{
	use HasUuids;

	public const PIN_REFERENCE = 'frame_bounds_v1';

	protected $table = 'fmi_review_screens';

	protected $fillable = [
		'project_id',
		'revision_id',
		'figma_frame_id',
		'file_key',
		'node_id',
		'name',
		'width',
		'height',
		'surface_confirmed',
		'figma_version',
		'position',
		'created_by',
	];

	protected function casts(): array
	{
		return [
			'width' => 'integer',
			'height' => 'integer',
			'surface_confirmed' => 'boolean',
			'position' => 'integer',
			'created_by' => 'integer',
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

	public function revision(): BelongsTo
	{
		return $this->belongsTo(ReviewRevision::class, 'revision_id');
	}

	public function frame(): BelongsTo
	{
		return $this->belongsTo(FigmaFrame::class, 'figma_frame_id');
	}

	public function threads(): HasMany
	{
		return $this->hasMany(ReviewThread::class, 'screen_id');
	}

	public function preview(): ?FigmaPreview
	{
		return $this->frame?->previews()->where('node_id', $this->node_id)->first();
	}
}
