<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMember extends Model
{
	protected $table = 'fmi_project_members';

	protected $fillable = [
		'project_id',
		'user_id',
		'added_by',
	];

	protected function casts(): array
	{
		return [
			'user_id' => 'integer',
			'added_by' => 'integer',
		];
	}

	public function project(): BelongsTo
	{
		return $this->belongsTo(Project::class);
	}
}
