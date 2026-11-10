<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ostatnia wgrana wersja schematu strony (format `fmi-site-schema`, wtyczka Fill Me In Sync). Jedna na projekt.
 */
class SiteSchema extends Model
{
	protected $table = 'fmi_site_schemas';

	protected $fillable = ['project_id', 'site_url', 'site_name', 'schema_version', 'document', 'uploaded_by', 'uploaded_at'];

	protected function casts(): array
	{
		return ['document' => 'array', 'uploaded_by' => 'integer', 'uploaded_at' => 'datetime'];
	}

	public function project(): BelongsTo
	{
		return $this->belongsTo(Project::class);
	}
}
