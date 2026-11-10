<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\ReviewThreadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Wątek uwag w Design Review: numer w projekcie, ekran (albo opis kontekstu), opcjonalna pinezka, status.
 * Pinezka (pin_x, pin_y 0–1, `pin_reference`) wyłącznie na ekranie z potwierdzoną powierzchnią — patrz ReviewScreen.
 *
 * @property int $id
 * @property int $project_id
 * @property ?int $revision_id
 * @property ReviewThreadStatus $status
 */
class ReviewThread extends Model
{
	use HasUuids;

	protected $table = 'fmi_review_threads';

	protected $fillable = [
		'project_id',
		'revision_id',
		'number',
		'screen_id',
		'context',
		'pin_x',
		'pin_y',
		'pin_reference',
		'figma_version',
		'status',
		'lock_version',
		'status_changed_at',
		'created_by_type',
		'created_by_user_id',
		'created_by_access_id',
		'resolved_at',
		'resolved_by',
	];

	protected $attributes = [
		'status' => 'open',
	];

	protected function casts(): array
	{
		return [
			'status' => ReviewThreadStatus::class,
			'created_by_type' => ActorType::class,
			'created_by_user_id' => 'integer',
			'resolved_by' => 'integer',
			'resolved_at' => 'datetime',
			'status_changed_at' => 'datetime',
			'lock_version' => 'integer',
			'number' => 'integer',
			'pin_x' => 'float',
			'pin_y' => 'float',
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

	public function screen(): BelongsTo
	{
		return $this->belongsTo(ReviewScreen::class, 'screen_id');
	}

	public function comments(): HasMany
	{
		return $this->hasMany(ReviewComment::class, 'thread_id')->orderBy('id');
	}

	public function events(): HasMany
	{
		return $this->hasMany(ReviewThreadEvent::class, 'thread_id')->orderBy('id');
	}

	public function isResolved(): bool
	{
		return $this->status === ReviewThreadStatus::Resolved;
	}

	public function hasPin(): bool
	{
		return $this->pin_x !== null && $this->pin_y !== null;
	}
}
