<?php

namespace App\Services;

use App\Enums\ReviewRevisionStatus;
use App\Enums\ReviewThreadStatus;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ReviewComment;
use App\Models\ReviewScreen;
use App\Models\ReviewThread;
use App\Support\ReviewGuest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use WP_User;

/**
 * Nowa uwaga (wątek + pierwsza wiadomość) w bieżącej rewizji projektu.
 *
 * Kontekst: ekran rewizji (wiarygodny — frame z API) albo opis podany przez autora. Pinezka tylko na ekranie
 * z potwierdzoną powierzchnią; bez niej x, y są odrzucane, a nie „przybliżane”.
 */
class ReviewCommentService
{
	public function __construct(private ActivityLogger $activity, private NotificationService $notifications)
	{
	}

	/**
	 * `request_id` (opcjonalny, z przeglądarki): ponowienie tego samego żądania po timeoucie zwraca już
	 * utworzony wątek zamiast drugiego (sprawdzane pod blokadą projektu + unikalny indeks).
	 *
	 * @param array{body: string, screen_id?: ?int, context?: ?string, x?: ?float, y?: ?float, request_id?: ?string} $data
	 */
	public function create(Project $project, array $data, WP_User|ReviewGuest $by): ReviewThread
	{
		$author = ReviewGuest::author($by);
		$requestId = $data['request_id'] ?? null;

		if ($existing = $this->existing($project, $requestId)) {
			return $existing;
		}

		$revision = $project->reviewRevisions()->first();

		if (! $revision || $revision->status === ReviewRevisionStatus::Superseded) {
			throw ValidationException::withMessages(['body' => 'Projekt nie ma bieżącej rewizji do komentowania.']);
		}

		$screen = null;

		if (! empty($data['screen_id'])) {
			$screen = ReviewScreen::query()
				->where('project_id', $project->id)
				->where('revision_id', $revision->id)
				->find((int) $data['screen_id']);

			if (! $screen) {
				throw ValidationException::withMessages(['screen_id' => 'Wybrany ekran nie należy do bieżącej rewizji tego projektu.']);
			}
		}

		$hasPin = isset($data['x'], $data['y']);

		if ($hasPin && ! $screen?->surface_confirmed) {
			throw ValidationException::withMessages(['x' => 'Pinezkę można postawić tylko na ekranie z potwierdzoną powierzchnią. Dodaj uwagę do ekranu bez pinezki.']);
		}

		$context = trim((string) ($data['context'] ?? ''));

		if (! $screen && $context === '') {
			throw ValidationException::withMessages(['context' => 'Wybierz ekran albo opisz, którego miejsca dotyczy uwaga.']);
		}

		return DB::transaction(function () use ($project, $revision, $screen, $data, $hasPin, $context, $author, $requestId) {
			Project::query()->whereKey($project->id)->lockForUpdate()->first();

			if ($existing = $this->existing($project, $requestId)) {
				return $existing;
			}

			$number = (int) ReviewThread::query()->where('project_id', $project->id)->max('number') + 1;

			$thread = ReviewThread::create([
				'project_id' => $project->id,
				'revision_id' => $revision->id,
				'number' => $number,
				'screen_id' => $screen?->id,
				'context' => $screen ? null : mb_substr($context, 0, 190),
				'pin_x' => $hasPin ? round((float) $data['x'], 6) : null,
				'pin_y' => $hasPin ? round((float) $data['y'], 6) : null,
				'pin_reference' => $hasPin ? ReviewScreen::PIN_REFERENCE : null,
				'figma_version' => $screen?->figma_version,
				'status' => ReviewThreadStatus::Open,
				'created_by_type' => $author['type'],
				'created_by_user_id' => $author['user'],
				'created_by_access_id' => $author['access'],
			]);

			$comment = ReviewComment::create([
				'project_id' => $project->id,
				'thread_id' => $thread->id,
				'request_id' => $requestId,
				'body' => trim($data['body']),
				'author_type' => $author['type'],
				'author_user_id' => $author['user'],
				'author_access_id' => $author['access'],
				'author_name' => $author['name'],
			]);

			$this->activity->log('review.comment_added', $author['actor'], $project, $thread, array_filter([
				'number' => $number,
				'revision' => $revision->number,
				'pin' => $hasPin,
				'guest_name' => $author['type'] === \App\Enums\ActorType::Client ? $author['name'] : null,
			], fn ($value) => $value !== null));
			$this->notifications->notify($project, Notification::COMMENT, $comment->id, [
				'number' => $number,
				'thread' => $thread->id,
				'actor' => $author['label'],
				'excerpt' => NotificationService::excerpt($comment->body),
			], $author['user']);

			return $thread;
		});
	}

	private function existing(Project $project, ?string $requestId): ?ReviewThread
	{
		if (! $requestId) {
			return null;
		}

		$comment = ReviewComment::query()->where('project_id', $project->id)->where('request_id', $requestId)->first();

		return $comment?->thread()->first();
	}
}
