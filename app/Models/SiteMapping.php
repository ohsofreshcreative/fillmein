<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dopasowanie elementu projektu do schematu strony: `page` → ID wpisu, `section` → blok (+ pozycja na stronie),
 * `field` → klucz pola ACF. `target` null — bez pary. Zapis wyłącznie przez SiteMappingService.
 */
class SiteMapping extends Model
{
	public const PAGE = 'page';

	public const SECTION = 'section';

	public const FIELD = 'field';

	protected $table = 'fmi_site_mappings';

	protected $fillable = ['project_id', 'mappable_type', 'mappable_id', 'target', 'position', 'confidence', 'source'];

	protected function casts(): array
	{
		return ['position' => 'integer', 'confidence' => 'float'];
	}

	public function isManual(): bool
	{
		return $this->source === 'manual';
	}
}
