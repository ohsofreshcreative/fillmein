<?php

namespace App\Models;

use App\Enums\ActorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Niemodyfikowalna historia treści. Rewizje znikają tylko kaskadowo, razem z wartością pola.
 */
class FieldRevision extends Model
{
	protected $table = 'fmi_field_revisions';

	protected static function booted(): void
	{
		$immutable = fn () => throw new LogicException('Historia treści jest niemodyfikowalna.');

		static::updating($immutable);
		static::deleting($immutable);
	}

	protected $fillable = [
		'project_id',
		'field_value_id',
		'version',
		'value',
		'actor_type',
		'actor_user_id',
		'actor_access_id',
	];

	protected function casts(): array
	{
		return [
			'version' => 'integer',
			'actor_type' => ActorType::class,
			'actor_user_id' => 'integer',
			'actor_access_id' => 'integer',
		];
	}

	public function fieldValue(): BelongsTo
	{
		return $this->belongsTo(FieldValue::class);
	}
}
