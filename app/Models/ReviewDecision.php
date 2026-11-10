<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\ReviewRevisionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Decyzja o rewizji Design Review: akceptacja albo prośba o poprawki. Jedna na rewizję, tylko dopisywana.
 *
 * `identification` — jak ustalono aktora (to nie jest podpis elektroniczny):
 * - `wp_account`: zalogowany pracownik (konto WordPress, uwierzytelnione);
 * - `review_link` / `review_link_code`: osoba z linkiem recenzji (opcjonalnie z kodem dostępu); `actor_name` podała sama.
 *
 * `version_status`: `pinned` — wersja pliku Figmy z chwili decyzji odczytana z API (`figma_version`, `figma_last_modified`);
 * `unverified` — API nie podało wersji: decyzja dotyczy zawartości linku z chwili decyzji bez dowodu, jaka to była wersja.
 */
class ReviewDecision extends Model
{
	use HasUuids;

	public const UPDATED_AT = null;

	public const PINNED = 'pinned';

	public const UNVERIFIED = 'unverified';

	public const IDENTIFICATIONS = [
		'wp_account' => 'Konto Fill Me In (zalogowany pracownik)',
		'review_link' => 'Link recenzji — nazwa podana przez osobę, niepotwierdzona',
		'review_link_code' => 'Link recenzji z kodem dostępu — nazwa podana przez osobę, niepotwierdzona',
	];

	protected $table = 'fmi_review_decisions';

	protected $fillable = [
		'project_id',
		'revision_id',
		'request_id',
		'decision',
		'note',
		'actor_type',
		'actor_user_id',
		'actor_access_id',
		'actor_name',
		'identification',
		'revision_number',
		'figma_url',
		'figma_file_key',
		'figma_node_id',
		'figma_version',
		'figma_last_modified',
		'version_status',
	];

	protected function casts(): array
	{
		return [
			'decision' => ReviewRevisionStatus::class,
			'actor_type' => ActorType::class,
			'actor_user_id' => 'integer',
			'revision_number' => 'integer',
			'figma_last_modified' => 'datetime',
		];
	}

	public function uniqueIds(): array
	{
		return ['uuid'];
	}

	public function revision(): BelongsTo
	{
		return $this->belongsTo(ReviewRevision::class, 'revision_id');
	}

	public function isApproval(): bool
	{
		return $this->decision === ReviewRevisionStatus::Approved;
	}

	public function actorLabel(): string
	{
		if ($this->actor_type === ActorType::User && $this->actor_user_id) {
			$user = get_user_by('id', $this->actor_user_id);

			return $user ? $user->display_name : 'Usunięte konto';
		}

		return trim((string) $this->actor_name) !== '' ? $this->actor_name : 'Gość';
	}

	public function identificationLabel(): string
	{
		return self::IDENTIFICATIONS[$this->identification] ?? $this->identification;
	}
}
