<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Element repeatera ze stabilnym UUID (niezależnym od kolejności i od zmian konfiguracji pól).
 */
class RepeaterRow extends Model
{
	use HasUuids;
	use SoftDeletes;

	protected $table = 'fmi_repeater_rows';

	protected $fillable = [
		'project_id',
		'field_id',
		'parent_row_id',
		'position',
		'created_by_type',
		'created_by_user_id',
		'created_by_access_id',
	];

	protected function casts(): array
	{
		return [
			'position' => 'integer',
			'parent_row_id' => 'integer',
			'created_by_user_id' => 'integer',
			'created_by_access_id' => 'integer',
		];
	}

	public function uniqueIds(): array
	{
		return ['uuid'];
	}

	/**
	 * Poziom zagnieżdżenia: 0 — lista w sekcji, 1 — lista w elemencie listy.
	 */
	public function level(): int
	{
		$level = 0;

		for ($parent = $this->parentRow; $parent; $parent = $parent->parentRow) {
			$level++;
		}

		return $level;
	}

	public function field(): BelongsTo
	{
		return $this->belongsTo(Field::class);
	}

	public function parentRow(): BelongsTo
	{
		return $this->belongsTo(self::class, 'parent_row_id');
	}

	public function childRows(): HasMany
	{
		return $this->hasMany(self::class, 'parent_row_id')->orderBy('position');
	}

	public function values(): HasMany
	{
		return $this->hasMany(FieldValue::class, 'row_id');
	}
}
