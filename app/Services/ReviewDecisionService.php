<?php

namespace App\Services;

use App\Enums\ReviewRevisionStatus;
use App\Exceptions\ReviewConflictException;
use App\Integrations\Figma\FigmaClient;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ReviewDecision;
use App\Models\ReviewRevision;
use App\Support\ReviewGuest;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;
use WP_User;

/**
 * Decyzja o rewizji: akceptacja albo prośba o poprawki — dla konkretnej, bieżącej rewizji.
 *
 * - Jedna decyzja na rewizję (unikalny indeks + blokada wiersza rewizji): przy równoległych żądaniach wygrywa pierwszy
 *   zapis, kolejne dostają konflikt z treścią obowiązującej decyzji; ponowienie z tym samym `request_id` zwraca ją bez zmian.
 * - Decyzja nie przechodzi na nową rewizję: nowa zaczyna jako „Oczekuje na decyzję” (ReviewService::createRevision).
 * - Link Figmy nie jest niezmienną wersją: przy decyzji odczytujemy z API wersję pliku (`version`, `lastModified`)
 *   przez istniejące połączenie OAuth — tylko dla pliku z rewizji, bez dowolnych adresów. Gdy się nie da — `unverified`.
 */
class ReviewDecisionService
{
	public function __construct(private ActivityLogger $activity, private FigmaClient $figma, private NotificationService $notifications)
	{
	}

	public function decide(Project $project, ReviewRevision $revision, ReviewRevisionStatus $decision, ?string $note, string $requestId, WP_User|ReviewGuest $by): ReviewDecision
	{
		abort_unless($revision->project_id === $project->id, 404);
		abort_unless(in_array($decision, [ReviewRevisionStatus::Approved, ReviewRevisionStatus::ChangesRequested], true), 422);

		if ($existing = $this->byRequest($project, $requestId)) {
			return $existing;
		}

		// Odczyt wersji z Figmy przed transakcją — bez trzymania blokady w czasie żądania HTTP.
		$version = $this->figmaVersion($revision);
		$author = ReviewGuest::author($by);

		try {
			return DB::transaction(function () use ($project, $revision, $decision, $note, $requestId, $by, $author, $version) {
				$locked = ReviewRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();

				if ($existing = $this->byRequest($project, $requestId)) {
					return $existing;
				}

				$this->assertDecidable($project, $locked);

				$record = ReviewDecision::create([
					'project_id' => $project->id,
					'revision_id' => $locked->id,
					'request_id' => $requestId,
					'decision' => $decision,
					'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
					'actor_type' => $author['type'],
					'actor_user_id' => $author['user'],
					'actor_access_id' => $author['access'],
					'actor_name' => $by instanceof WP_User ? $by->display_name : $author['name'],
					'identification' => $by instanceof WP_User ? 'wp_account' : ($by->access->hasCode() ? 'review_link_code' : 'review_link'),
					'revision_number' => $locked->number,
					'figma_url' => $locked->figma_url,
					'figma_file_key' => $locked->figma_file_key,
					'figma_node_id' => $locked->figma_node_id,
					'figma_version' => $version['version'],
					'figma_last_modified' => $version['last_modified'],
					'version_status' => $version['version'] !== null ? ReviewDecision::PINNED : ReviewDecision::UNVERIFIED,
				]);

				$locked->update(['status' => $decision, 'figma_version' => $version['version'] ?? $locked->figma_version]);

				$this->activity->log('review.decision', $author['actor'], $project, $locked, array_filter([
					'decision' => $decision->value,
					'revision' => $locked->number,
					'identification' => $record->identification,
					'version' => $record->version_status,
					'guest_name' => $by instanceof WP_User ? null : $author['name'],
				], fn ($value) => $value !== null));
				$this->notifications->notify($project, Notification::DECISION, $record->id, [
					'revision' => $locked->number,
					'decision' => $decision->value,
					'actor' => $author['label'],
					'excerpt' => $record->note ? NotificationService::excerpt($record->note) : null,
				], $author['user']);

				return $record;
			});
		} catch (UniqueConstraintViolationException) {
			// Wyścig, którego nie zatrzymała blokada (np. inne połączenie): obowiązuje zapisana decyzja.
			if ($existing = $this->byRequest($project, $requestId)) {
				return $existing;
			}

			$this->assertDecidable($project, $revision->fresh());

			throw new ReviewConflictException('Decyzja dla tej rewizji została już zapisana.');
		}
	}

	/**
	 * Porównanie bieżącej wersji pliku Figmy z wersją utrwaloną w decyzji (link mógł pokazywać już inną zawartość).
	 *
	 * @return array{status: 'same'|'changed'|'unknown', current: ?string, last_modified: ?Carbon}
	 */
	public function compare(ReviewDecision $decision): array
	{
		$current = $this->figmaVersion($decision->revision ?? new ReviewRevision(['figma_file_key' => $decision->figma_file_key, 'figma_node_id' => $decision->figma_node_id]));

		if ($decision->figma_version === null || $current['version'] === null) {
			return ['status' => 'unknown', 'current' => $current['version'], 'last_modified' => $current['last_modified']];
		}

		return [
			'status' => $current['version'] === $decision->figma_version ? 'same' : 'changed',
			'current' => $current['version'],
			'last_modified' => $current['last_modified'],
		];
	}

	public function byRequest(Project $project, string $requestId): ?ReviewDecision
	{
		return ReviewDecision::query()->where('project_id', $project->id)->where('request_id', $requestId)->first();
	}

	private function assertDecidable(Project $project, ReviewRevision $revision): void
	{
		$existing = ReviewDecision::query()->where('revision_id', $revision->id)->first();

		if ($existing) {
			throw new ReviewConflictException(
				'Rewizja ' . $revision->number . ' ma już decyzję: ' . mb_strtolower($existing->decision->label()) . ' (' . $existing->actorLabel() . ', ' . $existing->created_at?->format('d.m.Y H:i') . '). Kolejna decyzja wymaga nowej rewizji.',
				['decision' => $existing->decision->value, 'revision' => $revision->number],
			);
		}

		$current = ReviewRevision::query()->where('project_id', $project->id)->orderByDesc('number')->first();

		if (! $current || $current->id !== $revision->id || $revision->status === ReviewRevisionStatus::Superseded) {
			throw new ReviewConflictException('Ta rewizja nie jest już bieżąca — decyzję podejmuje się dla najnowszej rewizji.', ['revision' => $revision->number]);
		}
	}

	/**
	 * @return array{version: ?string, last_modified: ?Carbon}
	 */
	private function figmaVersion(ReviewRevision $revision): array
	{
		if (! $revision->figma_file_key || ! $revision->figma_node_id || ! $this->figma->isConfigured()) {
			return ['version' => null, 'last_modified' => null];
		}

		try {
			$data = $this->figma->fileNodes($revision->figma_file_key, [$revision->figma_node_id], 1);
		} catch (Throwable) {
			return ['version' => null, 'last_modified' => null];
		}

		$version = isset($data['version']) && is_scalar($data['version']) ? mb_substr((string) $data['version'], 0, 64) : null;

		try {
			$modified = isset($data['lastModified']) ? Carbon::parse((string) $data['lastModified']) : null;
		} catch (Throwable) {
			$modified = null;
		}

		return ['version' => $version !== '' ? $version : null, 'last_modified' => $modified];
	}
}
