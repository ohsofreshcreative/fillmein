<?php

namespace App\Models;

use App\Enums\ActorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aktualna treść pola. Zapisy wyłącznie przez App\Services\ContentService
 * (optymistyczna blokada na kolumnie `version` + historia rewizji).
 */
class FieldValue extends Model
{
	protected $table = 'fmi_field_values';

	protected $fillable = [
		'project_id',
		'field_id',
		'row_id',
		'row_key',
		'value',
		'version',
		'updated_by_type',
		'updated_by_user_id',
		'updated_by_access_id',
	];

	protected $attributes = [
		'row_key' => '',
		'version' => 0,
	];

	protected function casts(): array
	{
		return [
			'review_requested_at' => 'datetime',
			'review_requested_by' => 'integer',
			'version' => 'integer',
			'row_id' => 'integer',
			'updated_by_type' => ActorType::class,
			'updated_by_user_id' => 'integer',
			'updated_by_access_id' => 'integer',
		];
	}

	public function field(): BelongsTo
	{
		return $this->belongsTo(Field::class);
	}

	public function row(): BelongsTo
	{
		return $this->belongsTo(RepeaterRow::class, 'row_id');
	}

	/**
	 * Pliki pola image/gallery w kolejności.
	 */
	public function assets(): BelongsToMany
	{
		return $this->belongsToMany(Asset::class, 'fmi_field_value_assets')
			->withPivot('position')
			->withTimestamps()
			->orderByPivot('position');
	}

	public function revisions(): HasMany
	{
		return $this->hasMany(FieldRevision::class)->orderByDesc('version');
	}
}
