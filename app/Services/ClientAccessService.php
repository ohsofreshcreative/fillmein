<?php

namespace App\Services;

use App\Models\ClientAccess;
use App\Models\Project;
use App\Support\Actor;
use App\Support\SecretBox;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use WP_User;

/**
 * Generowanie, weryfikacja i unieważnianie linków klientów.
 *
 * Token: 256 bitów z CSPRNG, kodowany base64url. Wyszukiwanie po SHA-256 (hex); link Treści ma też kopię
 * zaszyfrowaną SecretBoxem, żeby panel pokazywał adres (link Design Review — nie). SHA-256 —
 * przy tej entropii zwykły hash wystarcza i pozwala na wyszukiwanie po indeksie
 * (bez soli, która uniemożliwiłaby lookup, i bez zależności od rotacji soli WP).
 */
class ClientAccessService
{
	public function __construct(private ActivityLogger $activity)
	{
	}

	public function generate(Project $project, WP_User $creator, ?CarbonInterface $expiresAt = null, ?string $label = null): IssuedClientAccess
	{
		$token = $this->newToken();

		$ttl = config('fillmein.client_access.default_ttl_days');
		$expiresAt ??= $ttl ? now()->addDays((int) $ttl) : null;

		$access = $project->clientAccesses()->create([
			'token_hash' => $this->hash($token),
			'token_sealed' => SecretBox::seal($token),
			'label' => $label,
			'created_by' => $creator->ID,
			'expires_at' => $expiresAt,
		]);

		$this->activity->log('client_access.generated', Actor::user($creator), $project, $access, [
			'expires_at' => $expiresAt?->toIso8601String(),
		]);

		return new IssuedClientAccess($access, $token);
	}

	/**
	 * Unieważnia wszystkie aktywne linki projektu i wydaje nowy.
	 */
	public function regenerate(Project $project, WP_User $creator, ?CarbonInterface $expiresAt = null, ?string $label = null): IssuedClientAccess
	{
		return DB::transaction(function () use ($project, $creator, $expiresAt, $label) {
			$project->clientAccesses()->content()->active()->get()
				->each(fn (ClientAccess $access) => $this->revoke($access, $creator));

			return $this->generate($project, $creator, $expiresAt, $label);
		});
	}

	public function revoke(ClientAccess $access, WP_User $user): void
	{
		if ($access->revoked_at) {
			return;
		}

		$access->forceFill([
			'revoked_at' => now(),
			'revoked_by' => $user->ID,
		])->save();

		$this->activity->log('client_access.revoked', Actor::user($user), $access->project, $access);
	}

	/**
	 * Link recenzji Design Review: zawsze z terminem ważności, jawne możliwości, opcjonalny kod dostępu
	 * (tylko skrót password_hash). Token jak w linkach Treści; zakres `review` nie otwiera edytora Treści.
	 */
	public function generateReview(Project $project, WP_User $creator, ?CarbonInterface $expiresAt, ?string $label, bool $canComment, bool $canApprove, #[\SensitiveParameter] ?string $code = null): IssuedClientAccess
	{
		$token = $this->newToken();

		$access = $project->clientAccesses()->create([
			'scope' => ClientAccess::SCOPE_REVIEW,
			'token_hash' => $this->hash($token),
			'label' => $label,
			'can_comment' => $canComment,
			'can_approve' => $canApprove,
			'code_hash' => $code !== null && $code !== '' ? password_hash($code, PASSWORD_DEFAULT) : null,
			'created_by' => $creator->ID,
			'expires_at' => $expiresAt,
		]);

		$this->activity->log('client_access.review_generated', Actor::user($creator), $project, $access, [
			'expires_at' => $expiresAt?->toIso8601String(),
			'can_comment' => $canComment,
			'can_approve' => $canApprove,
			'code' => $access->hasCode(),
		]);

		return new IssuedClientAccess($access, $token, '/r/');
	}

	/**
	 * Aktywny link Treści dla tokenu albo null. Projekt usunięty (soft delete) unieważnia dostęp.
	 */
	public function resolve(#[\SensitiveParameter] string $token): ?ClientAccess
	{
		return $this->find($token, ClientAccess::SCOPE_CONTENT);
	}

	/**
	 * Aktywny link recenzji dla tokenu albo null (projekt musi mieć włączony moduł Design Review).
	 */
	public function resolveReview(#[\SensitiveParameter] string $token): ?ClientAccess
	{
		$access = $this->find($token, ClientAccess::SCOPE_REVIEW);

		return $access && $access->project->review_enabled ? $access : null;
	}

	/**
	 * Ponowna weryfikacja przy każdym żądaniu gościa (sesja przechowuje tylko ID linku): unieważnienie
	 * i wygaśnięcie działają od razu.
	 */
	public function activeReview(int $id): ?ClientAccess
	{
		$access = ClientAccess::query()->review()->active()->whereKey($id)->whereHas('project')->with('project')->first();

		if (! $access || ! $access->project->review_enabled) {
			return null;
		}

		$this->touch($access);

		return $access;
	}

	public function checkCode(ClientAccess $access, #[\SensitiveParameter] string $code): bool
	{
		return $access->hasCode() && password_verify($code, $access->code_hash);
	}

	private function find(#[\SensitiveParameter] string $token, string $scope): ?ClientAccess
	{
		if (! preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token)) {
			return null;
		}

		$access = ClientAccess::query()
			->where('scope', $scope)
			->active()
			->where('token_hash', $this->hash($token))
			->whereHas('project')
			->with('project')
			->first();

		if ($access) {
			$this->touch($access);
		}

		return $access;
	}

	public function hash(#[\SensitiveParameter] string $token): string
	{
		return hash('sha256', $token);
	}

	private function newToken(): string
	{
		$bytes = random_bytes((int) config('fillmein.client_access.token_bytes', 32));

		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}

	private function touch(ClientAccess $access): void
	{
		$interval = (int) config('fillmein.client_access.touch_interval', 300);

		if ($access->last_used_at && $access->last_used_at->gt(now()->subSeconds($interval))) {
			return;
		}

		$access->forceFill(['last_used_at' => now()])->saveQuietly();
	}
}
