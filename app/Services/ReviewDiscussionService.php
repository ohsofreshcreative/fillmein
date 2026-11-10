<?php

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\ReviewThreadStatus;
use App\Exceptions\ReviewConflictException;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ReviewComment;
use App\Models\ReviewThread;
use App\Models\ReviewThreadEvent;
use App\Support\Actor;
use App\Support\ReviewGuest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use WP_User;

/**
 * Dyskusje w wątkach uwag: odpowiedzi, edycja własnych wiadomości, statusy (Nowy → W trakcie → Rozwiązany,
 * ponowne otwarcie). Uprawnienia sprawdza ProjectPolicy (FormRequest); tu — reguły stanu i współbieżności:
 *
 * - odpowiedź: `request_id` z przeglądarki; ponowienie tego samego żądania zwraca istniejący wpis (bez duplikatu),
 *   dwie różne odpowiedzi z dwóch sesji zapisują się obie (nic nie jest nadpisywane);
 * - status i edycja: `lock_version` z chwili wczytania; inna wersja → ReviewConflictException (nic nie zapisano),
 *   chyba że stan docelowy już obowiązuje (ponowienie po timeoucie) — wtedy bez zmian i bez wpisu w historii;
 * - w wątku rozwiązanym nie odpowiadamy i nie edytujemy — najpierw ponowne otwarcie (wpis w historii).
 */
class ReviewDiscussionService
{
	public function __construct(private ActivityLogger $activity, private NotificationService $notifications)
	{
	}

	public function reply(Project $project, ReviewThread $thread, string $body, string $requestId, WP_User|ReviewGuest $by): ReviewComment
	{
		$author = ReviewGuest::author($by);
		$this->assertInProject($project, $thread);

		try {
			return DB::transaction(function () use ($project, $thread, $body, $requestId, $author) {
				$locked = ReviewThread::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();
				$existing = $this->byRequest($project, $requestId);

				if ($existing) {
					return $this->sameThread($existing, $locked);
				}

				if ($locked->isResolved()) {
					throw ValidationException::withMessages(['body' => 'Uwaga jest rozwiązana — otwórz ją ponownie, żeby odpowiedzieć.']);
				}

				$comment = ReviewComment::create([
					'project_id' => $project->id,
					'thread_id' => $locked->id,
					'request_id' => $requestId,
					'body' => trim($body),
					'author_type' => $author['type'],
					'author_user_id' => $author['user'],
					'author_access_id' => $author['access'],
					'author_name' => $author['name'],
				]);
				$locked->touch();

				$this->activity->log('review.reply_added', $author['actor'], $project, $locked, array_filter([
					'number' => $locked->number,
					'guest_name' => $author['type'] === \App\Enums\ActorType::Client ? $author['name'] : null,
				], fn ($value) => $value !== null));
				$this->notifications->notify($project, Notification::REPLY, $comment->id, [
					'number' => $locked->number,
					'thread' => $locked->id,
					'actor' => $author['label'],
					'excerpt' => NotificationService::excerpt($comment->body),
				], $author['user']);

				return $comment;
			});
		} catch (UniqueConstraintViolationException) {
			// Równoległe ponowienie z tym samym request_id: zwycięzca już zapisał wpis.
			return $this->sameThread($this->byRequest($project, $requestId), $thread);
		}
	}

	public function edit(Project $project, ReviewComment $comment, string $body, int $version, WP_User $by): ReviewComment
	{
		return DB::transaction(function () use ($project, $comment, $body, $version, $by) {
			$locked = ReviewComment::query()->where('project_id', $project->id)->whereKey($comment->id)->lockForUpdate()->firstOrFail();
			$thread = $locked->thread()->firstOrFail();
			$body = trim($body);

			if ($locked->body === $body) {
				return $locked;
			}

			if ($thread->isResolved()) {
				throw ValidationException::withMessages(['body' => 'Uwaga jest rozwiązana — otwórz ją ponownie, żeby edytować wiadomość.']);
			}

			if ($locked->lock_version !== $version) {
				throw new ReviewConflictException('Ta wiadomość została w międzyczasie zmieniona. Twoja wersja nie została zapisana — porównaj z bieżącą treścią.', [
					'body' => $locked->body,
					'version' => $locked->lock_version,
				]);
			}

			ReviewThreadEvent::create([
				'project_id' => $project->id,
				'thread_id' => $thread->id,
				'comment_id' => $locked->id,
				'type' => ReviewThreadEvent::COMMENT_EDITED,
				'previous_body' => $locked->body,
				'actor_type' => ActorType::User,
				'actor_user_id' => $by->ID,
			]);
			$locked->update(['body' => $body, 'lock_version' => $locked->lock_version + 1, 'edited_at' => now()]);

			$this->activity->log('review.comment_edited', Actor::user($by), $project, $thread, ['number' => $thread->number]);

			return $locked;
		});
	}

	public function changeStatus(Project $project, ReviewThread $thread, ReviewThreadStatus $to, int $version, WP_User $by): ReviewThread
	{
		$this->assertInProject($project, $thread);

		return DB::transaction(function () use ($project, $thread, $to, $version, $by) {
			$locked = ReviewThread::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();
			$from = $locked->status;

			if ($from === $to) {
				return $locked;
			}

			if ($locked->lock_version !== $version) {
				throw new ReviewConflictException('Status uwagi zmienił się w międzyczasie na „' . $from->label() . '”. Nic nie zmieniono — sprawdź i spróbuj ponownie.', [
					'status' => $from->value,
					'version' => $locked->lock_version,
				]);
			}

			$reopened = $from === ReviewThreadStatus::Resolved;
			ReviewThreadEvent::create([
				'project_id' => $project->id,
				'thread_id' => $locked->id,
				'type' => $reopened ? ReviewThreadEvent::REOPENED : ReviewThreadEvent::STATUS_CHANGED,
				'from_status' => $from,
				'to_status' => $to,
				'actor_type' => ActorType::User,
				'actor_user_id' => $by->ID,
			]);
			$locked->update([
				'status' => $to,
				'lock_version' => $locked->lock_version + 1,
				'status_changed_at' => now(),
				'resolved_at' => $to === ReviewThreadStatus::Resolved ? now() : null,
				'resolved_by' => $to === ReviewThreadStatus::Resolved ? $by->ID : null,
			]);

			$this->activity->log('review.thread_status', Actor::user($by), $project, $locked, [
				'number' => $locked->number,
				'from' => $from->value,
				'to' => $to->value,
			]);

			return $locked;
		});
	}

	public function byRequest(Project $project, ?string $requestId): ?ReviewComment
	{
		return $requestId ? ReviewComment::query()->where('project_id', $project->id)->where('request_id', $requestId)->first() : null;
	}

	private function sameThread(?ReviewComment $existing, ReviewThread $thread): ReviewComment
	{
		if (! $existing || $existing->thread_id !== $thread->id) {
			throw ValidationException::withMessages(['body' => 'Identyfikator żądania został już użyty w innym wątku. Odśwież stronę.']);
		}

		return $existing;
	}

	private function assertInProject(Project $project, ReviewThread $thread): void
	{
		abort_unless($thread->project_id === $project->id, 404);
	}
}
