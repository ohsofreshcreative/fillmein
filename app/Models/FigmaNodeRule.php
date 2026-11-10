<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Decyzja pracownika o warstwie frame'u podstrony: wykluczenie z importu albo przywrócenie
 * warstwy wykluczonej automatycznie. Zapisy: App\Services\FigmaImportService.
 */
class FigmaNodeRule extends Model
{
	public const EXCLUDE = 'exclude';
	public const INCLUDE = 'include';

	protected $table = 'fmi_figma_node_rules';

	protected $fillable = [
		'project_id',
		'page_id',
		'node_id',
		'node_name',
		'action',
		'created_by',
	];

	protected function casts(): array
	{
		return [
			'created_by' => 'integer',
		];
	}

	public function page(): BelongsTo
	{
		return $this->belongsTo(Page::class);
	}
}
