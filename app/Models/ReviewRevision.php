<?php

namespace App\Models;

use App\Enums\ReviewRevisionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Rewizja projektu w Design Review. Zapis odnośnika prototypu: App\Services\ReviewService. Należy do wspólnego projektu,
 * więc dostęp wynika z tych samych przypisań co Treści. Każda nowa rewizja wymaga nowej decyzji klienta.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property int $number
 * @property ReviewRevisionStatus $status
 */
class ReviewRevision extends Model
{
	use HasUuids;

	protected $table = 'fmi_review_revisions';

	protected $fillable = [
		'project_id',
		'number',
		'title',
		'status',
		'figma_file_key',
		'figma_node_id',
		'figma_url',
		'figma_version',
		'notes',
		'created_by',
		'published_at',
	];

	protected $attributes = [
		'status' => 'draft',
	];

	protected function casts(): array
	{
		return [
			'status' => ReviewRevisionStatus::class,
			'number' => 'integer',
			'created_by' => 'integer',
			'published_at' => 'datetime',
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

	public function threads(): HasMany
	{
		return $this->hasMany(ReviewThread::class, 'revision_id');
	}

	public function decision(): HasOne
	{
		return $this->hasOne(ReviewDecision::class, 'revision_id');
	}

	public function screens(): HasMany
	{
		return $this->hasMany(ReviewScreen::class, 'revision_id')->orderBy('position')->orderBy('id');
	}
}
