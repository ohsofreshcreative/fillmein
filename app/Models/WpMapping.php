<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Mapowanie elementu projektu na cel w WordPressie / ACF.
 */
class WpMapping extends Model
{
	protected $table = 'fmi_wp_mappings';

	protected $fillable = [
		'project_id',
		'mappable_type',
		'mappable_id',
		'wp_post_id',
		'acf_block',
		'acf_field_path',
		'settings',
	];

	protected function casts(): array
	{
		return [
			'wp_post_id' => 'integer',
			'settings' => 'array',
		];
	}

	public function project(): BelongsTo
	{
		return $this->belongsTo(Project::class);
	}

	public function mappable(): MorphTo
	{
		return $this->morphTo();
	}
}
