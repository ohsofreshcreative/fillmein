<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveReviewGuest;
use App\Exceptions\ReviewConflictException;
use App\Http\Requests\Guest\StoreGuestReplyRequest;
use App\Http\Requests\Guest\StoreGuestReviewDecisionRequest;
use App\Services\ReviewDecisionService;
use App\Http\Requests\Guest\StoreGuestReviewCommentRequest;
use App\Integrations\Figma\FigmaConnection;
use App\Integrations\Figma\FigmaEmbed;
use App\Models\ClientAccess;
use App\Services\ClientAccessService;
use App\Services\FigmaFrameService;
use App\Services\ReviewCommentService;
use App\Services\ReviewDiscussionService;
use App\Support\ReviewGuest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recenzja dla klienta bez konta (link zakresu `review`).
 *
 * `/r/{token}` tylko wymienia token na sesję (regeneracja ID sesji) i przekierowuje na `/review` — token nie trafia
 * do dalszych adresów, widoków ani dziennika. Projekt, rewizja, wątki i ekrany wynikają wyłącznie z linku w sesji:
 * identyfikatory z żądania są szukane w zakresie tego projektu (obce → 404).
 *
 * Dostęp do recenzji nie daje dostępu do Figmy: prototyp osadza Figma według uprawnień pliku i konta przeglądającego.
 */
class ReviewGuestController extends Controller
{
	public function __construct(private ClientAccessService $accesses)
	{
	}

	public function enter(Request $request, string $token): RedirectResponse|Response
	{
		// StartSession zapisuje adres każdego GET jako „poprzedni URL” w pliku sesji — tu zawierałby token.
		// Żądanie oznaczone jako prefetch jest przez Laravela pomijane (StartSession::storeCurrentUrl).
		$request->headers->set('Purpose', 'prefetch');
		$access = $this->accesses->resolveReview($token);
		unset($token);
		$request->route()->forgetParameter('token');

		if (! $access) {
			return response()->view('fillmein.client.invalid', [], 404);
		}

		$request->session()->regenerate(true);
		$request->session()->put(ResolveReviewGuest::SESSION_KEY, ['access' => $access->id, 'name' => null, 'unlocked' => ! $access->hasCode()]);

		return redirect()->route($access->hasCode() ? 'fmi.review.unlock' : 'fmi.review.show');
	}

	public function unlockForm(Request $request): Response
	{
		$access = $this->pending($request);

		return $access
			? response()->view('fillmein.client.review-unlock', ['access' => $access])
			: response()->view('fillmein.client.invalid', [], 403);
	}

	public function unlock(Request $request): RedirectResponse|Response
	{
		$access = $this->pending($request);

		if (! $access) {
			return response()->view('fillmein.client.invalid', [], 403);
		}

		$key = 'review-code|' . $access->id . '|' . $request->ip();
		$max = (int) config('fillmein.review.code_attempts', 5);

		if (RateLimiter::tooManyAttempts($key, $max)) {
			$minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

			return response()->view('fillmein.client.review-unlock', ['access' => $access, 'locked' => $minutes], 429);
		}

		$code = (string) $request->input('code', '');

		if (! $this->accesses->checkCode($access, $code)) {
			RateLimiter::hit($key, 15 * 60);

			return back()->withErrors(['code' => 'Nieprawidłowy kod dostępu.']);
		}

		RateLimiter::clear($key);
		$request->session()->regenerate(true);
		$request->session()->put(ResolveReviewGuest::SESSION_KEY, ['access' => $access->id, 'name' => null, 'unlocked' => true]);

		return redirect()->route('fmi.review.show');
	}

	public function show(Request $request, FigmaConnection $figma): Response
	{
		$guest = $this->guest($request);
		$project = $guest->access->project;
		$revision = $project->reviewRevisions()->with('decision')->first();
		$url = $revision?->figma_url;

		return response()
			->view('fillmein.client.review', [
				'guest' => $guest,
				'project' => $project,
				'revision' => $revision,
				'screens' => $revision?->screens()->get() ?? collect(),
				'threads' => $revision
					? $project->reviewThreads()->where('revision_id', $revision->id)->whereNotNull('number')->with(['comments', 'screen'])->orderBy('number')->get()
					: collect(),
				'embed' => FigmaEmbed::forLink($url, $figma->status()['client_id']),
			])
			->header('Content-Security-Policy', 'frame-src ' . config('fillmein.figma.embed.frame_src'));
	}

	public function saveName(Request $request): RedirectResponse
	{
		$guest = $this->guest($request);
		$data = $request->validate(
			['name' => ['required', 'string', 'max:' . ReviewGuest::NAME_MAX]],
			['name.required' => 'Podaj imię lub nazwę, którą zobaczy zespół.', 'name.max' => 'Nazwa może mieć najwyżej ' . ReviewGuest::NAME_MAX . ' znaków.'],
		);
		$name = trim(preg_replace('/\s+/u', ' ', strip_tags($data['name'])));

		if ($name === '') {
			throw ValidationException::withMessages(['name' => 'Podaj imię lub nazwę, którą zobaczy zespół.']);
		}

		$request->session()->put(ResolveReviewGuest::SESSION_KEY . '.name', $name);

		return redirect()->route('fmi.review.show')->with('status', 'Zapisano: ' . $name . '. Tak podpiszemy Twoje uwagi (nazwa podana przez Ciebie).');
	}

	public function storeComment(StoreGuestReviewCommentRequest $request, ReviewCommentService $comments): JsonResponse
	{
		$guest = $this->named($request);
		$thread = $comments->create($guest->access->project, $request->validated(), $guest);

		return response()->json(['number' => $thread->number, 'pin' => $thread->hasPin()], 201);
	}

	public function storeReply(StoreGuestReplyRequest $request, int $thread, ReviewDiscussionService $discussion): JsonResponse|RedirectResponse
	{
		$guest = $this->guest($request);
		$project = $guest->access->project;
		$model = $project->reviewThreads()->whereNotNull('number')->findOrFail($thread);
		$bag = 'thread' . $model->id;

		try {
			$guest = $this->named($request);
			$discussion->reply($project, $model, $request->validated('body'), $request->validated('request_id'), $guest);
		} catch (ValidationException $e) {
			return $request->expectsJson()
				? response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422)
				: back()->withErrors($e->errors(), $bag)->withInput();
		}

		return $request->expectsJson()
			? response()->json(['number' => $model->number], 201)
			: redirect()->to(route('fmi.review.show') . '#uwaga-' . $model->number)->with('status', 'Dodano odpowiedź do uwagi ' . $model->number . '.');
	}

	public function storeDecision(StoreGuestReviewDecisionRequest $request, ReviewDecisionService $decisions): JsonResponse|RedirectResponse
	{
		$guest = $this->guest($request);
		$project = $guest->access->project;
		$revision = $project->reviewRevisions()->where('number', (int) $request->validated('revision'))->firstOrFail();

		try {
			$guest = $this->named($request);
			$decision = $decisions->decide($project, $revision, $request->decision(), $request->validated('note'), $request->validated('request_id'), $guest);
		} catch (ReviewConflictException $e) {
			return $request->expectsJson()
				? response()->json(['message' => $e->getMessage(), 'current' => $e->current], 409)
				: redirect()->to(route('fmi.review.show') . '#decyzja')->withErrors(['decision' => $e->getMessage()], 'decision');
		} catch (ValidationException $e) {
			return $request->expectsJson()
				? response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422)
				: redirect()->to(route('fmi.review.show') . '#decyzja')->withErrors($e->errors(), 'decision')->withInput();
		}

		return $request->expectsJson()
			? response()->json(['decision' => $decision->decision->value, 'revision' => $decision->revision_number], 201)
			: redirect()->to(route('fmi.review.show') . '#decyzja')->with('status', 'Zapisano Twoją decyzję: ' . mb_strtolower($decision->decision->label()) . ' (' . mb_strtolower($revision->title) . ').');
	}

	public function screenImage(Request $request, int $screen, FigmaFrameService $frames): StreamedResponse
	{
		$guest = $this->guest($request);
		$model = $guest->access->project->reviewScreens()->findOrFail($screen);
		$preview = $model->preview();

		abort_unless($preview && $frames->ensureLocal($preview), 404);

		return Storage::disk('fmi')->response($preview->path, null, [
			'Content-Type' => $preview->mime_type ?: 'image/png',
			'Cache-Control' => 'private, max-age=300',
			'X-Content-Type-Options' => 'nosniff',
		], 'inline');
	}

	public function leave(Request $request): RedirectResponse|Response
	{
		$request->session()->forget(ResolveReviewGuest::SESSION_KEY);
		$request->session()->regenerate(true);

		return response()->view('fillmein.client.review-left');
	}

	private function guest(Request $request): ReviewGuest
	{
		return $request->attributes->get('reviewGuest');
	}

	/**
	 * Gość komentuje pod nazwą, którą sam podał (bez niej — 422 z prośbą o nazwę).
	 */
	private function named(Request $request): ReviewGuest
	{
		$guest = $this->guest($request);

		if ($guest->name === null) {
			throw ValidationException::withMessages(['body' => 'Najpierw podaj swoje imię lub nazwę — tak podpiszemy uwagę.']);
		}

		return $guest;
	}

	/**
	 * Link czekający na kod dostępu (sesja z `unlocked` = false).
	 */
	private function pending(Request $request): ?ClientAccess
	{
		$session = $request->session()->get(ResolveReviewGuest::SESSION_KEY);

		if (! is_array($session) || ! isset($session['access']) || ($session['unlocked'] ?? null) !== false) {
			return null;
		}

		$access = $this->accesses->activeReview((int) $session['access']);

		return $access?->hasCode() ? $access : null;
	}
}
