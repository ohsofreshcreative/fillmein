<?php

namespace App\Models;

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operacja eksportu JSON lub synchronizacji z WordPressem.
 */
class Export extends Model
{
	use HasUuids;

	protected $table = 'fmi_exports';

	protected $fillable = [
		'project_id',
		'type',
		'status',
		'user_id',
		'target',
		'file_path',
		'checksum',
		'summary',
		'error_message',
		'started_at',
		'finished_at',
	];

	protected function casts(): array
	{
		return [
			'type' => ExportType::class,
			'status' => ExportStatus::class,
			'user_id' => 'integer',
			'summary' => 'array',
			'started_at' => 'datetime',
			'finished_at' => 'datetime',
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
}
