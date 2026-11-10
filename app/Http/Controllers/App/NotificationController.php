<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\ReviewDecision;
use App\Models\ReviewThread;
use App\Support\Staff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Contracts\View\View;

/**
 * Powiadomienia zalogowanego pracownika. Każde wejście w powiadomienie ponownie sprawdza dostęp do projektu
 * (ProjectPolicy@viewReview) — odebrane uprawnienia blokują także link z wcześniejszego powiadomienia.
 */
class NotificationController extends Controller
{
	private const LIMIT = 50;

	public function index(Request $request): View
	{
		$user = Staff::current();
		$unreadOnly = $request->query('filtr') === 'nieprzeczytane';
		$query = Notification::query()->for($user)->with('project:id,name');

		return view('fillmein.notifications.index', [
			'notifications' => (clone $query)->when($unreadOnly, fn ($q) => $q->unread())->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIMIT)->get(),
			'unread' => (clone $query)->unread()->count(),
			'unreadOnly' => $unreadOnly,
		]);
	}

	public function open(int $notification): RedirectResponse
	{
		$user = Staff::current();
		$model = Notification::query()->where('user_id', $user->ID)->findOrFail($notification);
		$project = $model->project;

		abort_unless($project && $project->review_enabled && Gate::forUser($user)->allows('viewReview', $project), 404);

		$url = route('fmi.design-review.show', $project);
		$fragment = match ($model->type) {
			Notification::COMMENT, Notification::REPLY => ($thread = ReviewThread::query()->where('project_id', $project->id)->find((int) ($model->data['thread'] ?? 0)))
				? '#uwaga-' . $thread->number
				: null,
			Notification::DECISION => ReviewDecision::query()->where('project_id', $project->id)->whereKey($model->subject_id)->exists() ? '#decyzja' : null,
			default => null,
		};

		$model->read_at ??= now();
		$model->save();

		return redirect()->to($url . ($fragment ?? ''));
	}

	public function read(int $notification): RedirectResponse
	{
		Notification::query()->for(Staff::current())->whereKey($notification)->firstOrFail()->update(['read_at' => now()]);

		return back()->with('status', 'Oznaczono jako przeczytane.');
	}

	public function readAll(): RedirectResponse
	{
		$count = Notification::query()->for(Staff::current())->unread()->update(['read_at' => now()]);

		return redirect()->route('fmi.notifications.index')->with('status', $count ? 'Oznaczono wszystkie jako przeczytane.' : 'Brak nieprzeczytanych powiadomień.');
	}
}
