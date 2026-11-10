<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link dostępowy klienta. Wyszukiwanie wyłącznie po hashu tokenu. Linki Treści mają dodatkowo token zaszyfrowany
 * SecretBoxem (`token_sealed`), żeby zespół widział adres w panelu (decyzja właściciela z 2026-11-05).
 */
class ClientAccess extends Model
{
	/** Link edytora Treści (`/p/{token}`) — wszystkie linki sprzed etapu 09 Design Review. */
	public const SCOPE_CONTENT = 'content';

	/** Link recenzji Design Review (`/r/{token}`): odczyt + jawne możliwości `can_comment`, `can_approve`. */
	public const SCOPE_REVIEW = 'review';

	protected $table = 'fmi_client_accesses';

	protected $fillable = [
		'project_id',
		'scope',
		'token_hash',
		'token_sealed',
		'can_comment',
		'can_approve',
		'code_hash',
		'label',
		'created_by',
		'expires_at',
		'revoked_at',
		'revoked_by',
		'last_used_at',
	];

	protected $hidden = [
		'token_hash',
		'token_sealed',
		'code_hash',
	];

	protected $attributes = [
		'scope' => self::SCOPE_CONTENT,
	];

	protected function casts(): array
	{
		return [
			'created_by' => 'integer',
			'revoked_by' => 'integer',
			'expires_at' => 'datetime',
			'revoked_at' => 'datetime',
			'last_used_at' => 'datetime',
			'can_comment' => 'boolean',
			'can_approve' => 'boolean',
		];
	}

	/**
	 * Pełny adres linku Treści dla panelu zespołu; null dla linków bez zapisanej kopii (sprzed 2026-11-05) i recenzji.
	 */
	public function contentUrl(): ?string
	{
		if ($this->scope !== self::SCOPE_CONTENT || ! $this->token_sealed) {
			return null;
		}

		$token = \App\Support\SecretBox::open($this->token_sealed);

		return $token ? home_url('/p/' . $token) : null;
	}

	public function project(): BelongsTo
	{
		return $this->belongsTo(Project::class);
	}

	public function scopeActive(Builder $query): Builder
	{
		return $query->whereNull('revoked_at')
			->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
	}

	public function scopeContent(Builder $query): Builder
	{
		return $query->where('scope', self::SCOPE_CONTENT);
	}

	public function scopeReview(Builder $query): Builder
	{
		return $query->where('scope', self::SCOPE_REVIEW);
	}

	public function hasCode(): bool
	{
		return $this->code_hash !== null;
	}

	/**
	 * @return list<string> możliwości linku recenzji (do wyświetlenia)
	 */
	public function reviewAbilities(): array
	{
		return array_values(array_filter([
			'Podgląd',
			$this->can_comment ? 'Komentowanie' : null,
			$this->can_approve ? 'Akceptacja' : null,
		]));
	}

	public function isActive(): bool
	{
		return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
	}

	/**
	 * @return array{0: string, 1: string} etykieta i wariant <x-ui.badge>
	 */
	public function statusBadge(): array
	{
		return match (true) {
			$this->revoked_at !== null => ['Unieważniony', 'muted'],
			! $this->isActive() => ['Wygasł', 'warning'],
			default => ['Aktywny', 'success'],
		};
	}
}
