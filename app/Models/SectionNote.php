<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dodatkowe uwagi klienta lub zespołu pod sekcją. Zapis wyłącznie przez ContentService::saveSectionNote().
 */
class SectionNote extends Model
{
	protected $table = 'fmi_section_notes';

	protected $fillable = [
		'project_id',
		'section_id',
		'body',
		'version',
		'updated_by_type',
		'updated_by_user_id',
		'updated_by_access_id',
	];

	protected function casts(): array
	{
		return ['version' => 'integer', 'updated_by_user_id' => 'integer', 'updated_by_access_id' => 'integer'];
	}

	public function section(): BelongsTo
	{
		return $this->belongsTo(Section::class);
	}
}
