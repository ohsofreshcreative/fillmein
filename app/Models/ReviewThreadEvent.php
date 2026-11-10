<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\ReviewThreadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historia wątku: zmiana statusu (`status_changed`, `reopened`) albo edycja wiadomości (`comment_edited`,
 * `previous_body` = treść sprzed edycji). Wpisy tylko dopisywane.
 */
class ReviewThreadEvent extends Model
{
	public const UPDATED_AT = null;

	public const STATUS_CHANGED = 'status_changed';

	public const REOPENED = 'reopened';

	public const COMMENT_EDITED = 'comment_edited';

	protected $table = 'fmi_review_thread_events';

	protected $fillable = [
		'project_id',
		'thread_id',
		'comment_id',
		'type',
		'from_status',
		'to_status',
		'previous_body',
		'actor_type',
		'actor_user_id',
		'actor_access_id',
	];

	protected function casts(): array
	{
		return [
			'from_status' => ReviewThreadStatus::class,
			'to_status' => ReviewThreadStatus::class,
			'actor_type' => ActorType::class,
			'actor_user_id' => 'integer',
		];
	}

	public function thread(): BelongsTo
	{
		return $this->belongsTo(ReviewThread::class, 'thread_id');
	}

	public function actorName(): string
	{
		if ($this->actor_type === ActorType::User && $this->actor_user_id) {
			$user = get_user_by('id', $this->actor_user_id);

			return $user ? $user->display_name : 'Usunięte konto';
		}

		return 'Klient';
	}

	public function describe(): string
	{
		return match ($this->type) {
			self::REOPENED => 'ponownie otworzył(a) wątek (' . $this->from_status?->label() . ' → ' . $this->to_status?->label() . ')',
			self::STATUS_CHANGED => 'zmienił(a) status: ' . $this->from_status?->label() . ' → ' . $this->to_status?->label(),
			self::COMMENT_EDITED => 'edytował(a) wiadomość',
			default => $this->type,
		};
	}
}
