<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Podgląd warstwy Figmy: referencja do tymczasowego adresu z API i kopia na prywatnym dysku `fmi`.
 * `remote_url` jest podpisanym adresem — nie wysyłamy go do przeglądarki ani do dziennika.
 */
class FigmaPreview extends Model
{
	protected $table = 'fmi_figma_previews';

	protected $fillable = [
		'project_id',
		'frame_id',
		'file_key',
		'node_id',
		'node_name',
		'position',
		'format',
		'file_version',
		'remote_url',
		'remote_expires_at',
		'path',
		'mime_type',
		'width',
		'height',
		'bytes',
		'fetched_at',
	];

	protected $hidden = ['remote_url'];

	protected function casts(): array
	{
		return [
			'position' => 'integer',
			'width' => 'integer',
			'height' => 'integer',
			'bytes' => 'integer',
			'remote_expires_at' => 'datetime',
			'fetched_at' => 'datetime',
		];
	}

	public function frame(): BelongsTo
	{
		return $this->belongsTo(FigmaFrame::class, 'frame_id');
	}

	public function project(): BelongsTo
	{
		return $this->belongsTo(Project::class);
	}

	public function remoteUrlValid(): bool
	{
		return $this->remote_url !== null
			&& $this->remote_expires_at !== null
			&& $this->remote_expires_at->isFuture();
	}

	/**
	 * Podgląd z plikiem albo z adresem do pobrania — brakujący plik dociąga pierwsze wyświetlenie (ensureLocal).
	 */
	public function scopeDownloadable(Builder $query): Builder
	{
		return $query->where(fn (Builder $q) => $q->whereNotNull('path')->orWhereNotNull('remote_url'));
	}

	public function isFrame(): bool
	{
		return $this->frame && $this->frame->node_id === $this->node_id;
	}
}
