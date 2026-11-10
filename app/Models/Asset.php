<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Plik przesłany do projektu (zdjęcie). Przechowywany na prywatnym dysku `fmi`;
 * `path` i `variants` to ścieżki wewnętrzne — nigdy nie są publikowane jako URL.
 */
class Asset extends Model
{
	use HasUuids;
	use SoftDeletes;

	protected $table = 'fmi_assets';

	protected $fillable = [
		'project_id',
		'field_id',
		'disk',
		'path',
		'original_name',
		'mime_type',
		'extension',
		'size',
		'width',
		'height',
		'checksum',
		'alt',
		'caption',
		'variants',
		'metadata',
		'uploaded_by_type',
		'uploaded_by_user_id',
		'uploaded_by_access_id',
	];

	protected $hidden = [
		'disk',
		'path',
		'variants',
	];

	protected function casts(): array
	{
		return [
			'size' => 'integer',
			'width' => 'integer',
			'height' => 'integer',
			'variants' => 'array',
			'metadata' => 'array',
			'uploaded_by_user_id' => 'integer',
			'uploaded_by_access_id' => 'integer',
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

	public function field(): BelongsTo
	{
		return $this->belongsTo(Field::class);
	}

	public function fieldValues(): BelongsToMany
	{
		return $this->belongsToMany(FieldValue::class, 'fmi_field_value_assets')->withPivot('position');
	}

	public function mapping(): MorphOne
	{
		return $this->morphOne(WpMapping::class, 'mappable');
	}
}
