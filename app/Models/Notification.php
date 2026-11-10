<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use WP_User;

/**
 * Powiadomienie w panelu dla jednego pracownika. Adres kontekstu wylicza kontroler po ponownej autoryzacji —
 * w bazie nie ma adresów ani tokenów.
 */
class Notification extends Model
{
	public const UPDATED_AT = null;

	public const COMMENT = 'review.comment';

	public const REPLY = 'review.reply';

	public const DECISION = 'review.decision';

	protected $table = 'fmi_notifications';

	protected $fillable = [
		'user_id',
		'project_id',
		'type',
		'event_key',
		'subject_id',
		'data',
		'read_at',
	];

	protected function casts(): array
	{
		return [
			'user_id' => 'integer',
			'subject_id' => 'integer',
			'data' => 'array',
			'read_at' => 'datetime',
			'created_at' => 'datetime',
		];
	}

	public function project(): BelongsTo
	{
		return $this->belongsTo(Project::class);
	}

	/**
	 * Powiadomienia osoby — tylko z projektów, które nadal widzi (odebrany dostęp ukrywa wcześniejsze wpisy).
	 */
	public function scopeFor(Builder $query, WP_User $user): Builder
	{
		return $query->where('user_id', $user->ID)
			->whereIn('project_id', Project::query()->visibleTo($user)->where('review_enabled', true)->select('id'));
	}

	public function scopeUnread(Builder $query): Builder
	{
		return $query->whereNull('read_at');
	}

	public function title(): string
	{
		$data = $this->data ?? [];
		$actor = (string) ($data['actor'] ?? 'Ktoś');

		return match ($this->type) {
			self::COMMENT => $actor . ' dodał(a) uwagę ' . ($data['number'] ?? ''),
			self::REPLY => $actor . ' odpowiedział(a) w uwadze ' . ($data['number'] ?? ''),
			self::DECISION => $actor . ' — ' . (($data['decision'] ?? '') === 'approved' ? 'akceptacja' : 'prośba o poprawki') . ': Rewizja ' . ($data['revision'] ?? ''),
			default => 'Powiadomienie',
		};
	}
}
