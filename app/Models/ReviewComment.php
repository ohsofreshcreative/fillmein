<?php

namespace App\Models;

use App\Enums\ActorType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wiadomość w wątku uwag. Treść jest niezaufana: w widokach tylko `{{ }}` (escapowanie).
 */
class ReviewComment extends Model
{
	use HasUuids;

	public const BODY_MAX = 2000;

	protected $table = 'fmi_review_comments';

	protected $fillable = [
		'project_id',
		'thread_id',
		'body',
		'author_type',
		'author_user_id',
		'author_access_id',
		'author_name',
		'request_id',
		'lock_version',
		'edited_at',
	];

	protected function casts(): array
	{
		return [
			'author_type' => ActorType::class,
			'author_user_id' => 'integer',
			'lock_version' => 'integer',
			'edited_at' => 'datetime',
		];
	}

	public function uniqueIds(): array
	{
		return ['uuid'];
	}

	public function thread(): BelongsTo
	{
		return $this->belongsTo(ReviewThread::class, 'thread_id');
	}

	public function isAuthoredBy(\WP_User $user): bool
	{
		return $this->author_type === ActorType::User && $this->author_user_id === $user->ID;
	}

	/**
	 * Nazwa autora do wyświetlenia (konto WP; usunięte konto — opis zastępczy).
	 */
	public function authorName(): string
	{
		if ($this->author_type === ActorType::User && $this->author_user_id) {
			$user = get_user_by('id', $this->author_user_id);

			return $user ? $user->display_name : 'Usunięte konto';
		}

		return trim((string) $this->author_name) !== '' ? $this->author_name : 'Gość';
	}

	public function isGuest(): bool
	{
		return $this->author_type === ActorType::Client;
	}
}
