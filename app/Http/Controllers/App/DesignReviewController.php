<?php

namespace App\Http\Controllers\App;

use App\Http\Requests\StoreReviewDecisionRequest;
use App\Models\ReviewDecision;
use App\Services\ReviewDecisionService;
use App\Http\Requests\StoreReviewLinkRequest;
use App\Services\ClientAccessService;
use App\Enums\ReviewThreadStatus;
use App\Exceptions\ReviewConflictException;
use App\Http\Requests\ChangeReviewStatusRequest;
use App\Http\Requests\StoreReviewReplyRequest;
use App\Http\Requests\UpdateReviewCommentRequest;
use App\Models\ReviewThread;
use App\Services\ReviewDiscussionService;
use Illuminate\Validation\ValidationException;
use App\Enums\ProjectModule;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Integrations\Figma\FigmaConnection;
use App\Integrations\Figma\FigmaEmbed;
use App\Http\Requests\SavePrototypeRequest;
use App\Http\Requests\StoreReviewCommentRequest;
use App\Http\Requests\StoreReviewScreenRequest;
use App\Integrations\Figma\FigmaException;
use App\Services\FigmaFrameService;
use App\Services\ReviewCommentService;
use App\Services\ReviewScreenService;
use App\Models\Project;
use App\Services\ReviewService;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Projekty — Design Review (/app/design-review). Te same projekty co w Treściach (wspólny model i zakres
 * Project::visibleTo), więc nowa pozycja menu nie tworzy drugiego systemu projektów.
 *
 * Lista obejmuje projekty z włączonym modułem Design Review (`review_enabled`); recenzja projektu
 * (`/app/design-review/{project}`) przechodzi przez `fmi.module:review` i ProjectPolicy@viewReview.
 *
 * Adres jest nowy, bo /app/projects/* to od zawsze moduł Treści: stare linki muszą dalej otwierać treści.
 */
class DesignReviewController extends Controller
{
	public function __construct(private ReviewService $reviews)
	{
	}

	public function index(Request $request): View
	{
		$this->authorize('viewAny', Project::class);

		$perPage = (int) config('fillmein.projects.per_page', 20);
		$query = Project::query()
			->visibleTo(Staff::current())
			->withModule(ProjectModule::Review)
			->where('status', '!=', ProjectStatus::Archived->value);

		$total = (clone $query)->count();
		$lastPage = max(1, (int) ceil($total / $perPage));
		$page = min(max(1, $request->integer('page', 1)), $lastPage);
		$projects = $query->latest('updated_at')->orderBy('id', 'desc')->forPage($page, $perPage)->get();

		return view('fillmein.design-review.index', [
			'projects' => $projects,
			'overview' => $this->reviews->overview($projects),
			'total' => $total,
			'page' => $page,
			'lastPage' => $lastPage,
			'pageUrl' => fn (int $number) => route('fmi.design-review.index', $number > 1 ? ['page' => $number] : []),
		]);
	}

	/**
	 * CSP ogranicza ramki tej strony do domen osadzenia Figmy (embed.figma.com przekierowuje ramkę do www.figma.com).
	 * Pozostałe dyrektywy bez zmian — aplikacja nie ma dziś globalnej CSP.
	 */
	public function show(Request $request, Project $project, FigmaConnection $figma): Response
	{
		$this->authorize('viewReview', $project);

		$overview = $this->reviews->overview(collect([$project]))[$project->id];
		$url = $overview['revision']?->figma_url;
		$threads = $overview['revision']
			? $project->reviewThreads()->where('revision_id', $overview['revision']->id)->whereNotNull('number')->with(['comments', 'screen', 'events'])->orderBy('number')->get()
			: collect();
		$filter = ReviewThreadStatus::fromFilter($request->query('status'));

		return response()
			->view('fillmein.design-review.show', [
				'project' => $project,
				'overview' => $overview,
				'revisions' => $project->reviewRevisions()->with('decision')->get(),
				'screens' => $overview['revision']?->screens()->get() ?? collect(),
				// Pinezki na ekranach: wszystkie uwagi rewizji; lista: według filtra statusu.
				'threads' => $threads,
				'listed' => $filter ? $threads->filter(fn (ReviewThread $thread) => $thread->status === $filter)->values() : $threads,
				'filter' => $filter,
				'counts' => collect(ReviewThreadStatus::cases())->mapWithKeys(fn (ReviewThreadStatus $status) => [$status->value => $threads->where('status', $status)->count()]),
				'canManage' => Gate::allows('manageReviews', $project),
				'canShare' => Gate::allows('manageClientAccess', $project),
				'links' => Gate::allows('manageClientAccess', $project)
					? $project->clientAccesses()->review()->latest()->orderByDesc('id')->limit(20)->get()
					: collect(),
				'embed' => FigmaEmbed::forLink($url, $figma->status()['client_id']),
				'embedInvalid' => $url !== null && FigmaEmbed::forLink($url) === null,
			])
			->header('Content-Security-Policy', 'frame-src ' . config('fillmein.figma.embed.frame_src'));
	}

	public function savePrototype(SavePrototypeRequest $request, Project $project): RedirectResponse
	{
		$revision = $this->reviews->savePrototype($project, $request->validated('figma_url'), Staff::current());

		return redirect()
			->route('fmi.design-review.show', $project)
			->with('status', $revision->wasRecentlyCreated
				? 'Zapisano link prototypu jako ' . mb_strtolower($revision->title) . '.'
				: 'Zapisano link prototypu.');
	}

	public function storeComment(StoreReviewCommentRequest $request, Project $project, ReviewCommentService $comments): JsonResponse
	{
		$thread = $comments->create($project, $request->validated(), Staff::current());

		return response()->json(['number' => $thread->number, 'pin' => $thread->hasPin()], 201);
	}

	/**
	 * Podstrony recenzji (nazwa → link do frame'u). Bez rewizji powstaje szkic, więc link prototypu nie jest
	 * wymagany. Każdy wiersz pobierany osobno: błędny link nie blokuje pozostałych, a wraca do formularza z komunikatem.
	 */
	public function storeScreen(StoreReviewScreenRequest $request, Project $project, ReviewScreenService $screens): RedirectResponse
	{
		$revision = $this->reviews->revisionForScreens($project, Staff::current());
		$added = [];
		$failed = [];
		$errors = [];

		foreach ($request->rows() as $row) {
			try {
				$added[] = $screens->add($project, $revision->refresh(), $row['url'], Staff::current(), $row['name']);
			} catch (InvalidArgumentException | FigmaException $e) {
				$errors['screens.' . count($failed) . '.url'] = $e->getMessage();
				$failed[] = $row;
			}
		}

		$redirect = redirect()->route('fmi.design-review.show', $project);

		if ($added !== []) {
			$names = implode(', ', array_map(fn ($screen) => '„' . $screen->name . '”', $added));
			$withoutPins = count(array_filter($added, fn ($screen) => ! $screen->surface_confirmed));
			$redirect->with('status', (count($added) === 1 ? 'Dodano podstronę ' : 'Dodano podstrony: ') . $names . '.'
				. ($withoutPins ? ' Bez pinezek: ' . $withoutPins . ' (obraz nie odpowiada granicom frame\'u).' : ''));
		}

		if ($failed !== []) {
			// Pole `screen_url` zostaje dla starszych klientów formularza (jeden link).
			$errors['screen_url'] = reset($errors);

			return $redirect->withErrors($errors)->withInput(['screens' => $failed]);
		}

		return $redirect;
	}

	/**
	 * Obraz ekranu z prywatnego dysku, tylko w zakresie projektu.
	 */
	public function screenImage(Project $project, int $screen, FigmaFrameService $frames): StreamedResponse
	{
		$this->authorize('viewReview', $project);

		$model = $project->reviewScreens()->findOrFail($screen);
		$preview = $model->preview();

		abort_unless($preview && $frames->ensureLocal($preview), 404);

		return Storage::disk('fmi')->response($preview->path, null, [
			'Content-Type' => $preview->mime_type ?: 'image/png',
			'Cache-Control' => 'private, max-age=300',
			'X-Content-Type-Options' => 'nosniff',
		], 'inline');
	}

	public function storeReply(StoreReviewReplyRequest $request, Project $project, ReviewDiscussionService $discussion): JsonResponse|RedirectResponse
	{
		$thread = $request->thread();

		try {
			$comment = $discussion->reply($project, $thread, $request->validated('body'), $request->validated('request_id'), Staff::current());
		} catch (ValidationException $e) {
			return $request->expectsJson()
				? response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422)
				: back()->withErrors($e->errors(), 'thread' . $thread->id)->withInput();
		}

		return $request->expectsJson()
			? response()->json(['id' => $comment->id, 'number' => $thread->number], 201)
			: redirect()->to($this->threadUrl($request, $project, $thread))->with('status', 'Dodano odpowiedź do uwagi ' . $thread->number . '.');
	}

	public function updateComment(UpdateReviewCommentRequest $request, Project $project, ReviewDiscussionService $discussion): JsonResponse|RedirectResponse
	{
		$thread = $request->thread();
		$bag = 'comment' . $request->comment()->id;

		try {
			$comment = $discussion->edit($project, $request->comment(), $request->validated('body'), (int) $request->validated('version'), Staff::current());
		} catch (ReviewConflictException $e) {
			return $request->expectsJson()
				? response()->json(['message' => $e->getMessage(), 'current' => $e->current], 409)
				: back()->withErrors(['body' => $e->getMessage()], $bag)->withInput();
		} catch (ValidationException $e) {
			return $request->expectsJson()
				? response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422)
				: back()->withErrors($e->errors(), $bag)->withInput();
		}

		return $request->expectsJson()
			? response()->json(['id' => $comment->id, 'version' => $comment->lock_version])
			: redirect()->to($this->threadUrl($request, $project, $thread))->with('status', 'Zapisano zmianę wiadomości w uwadze ' . $thread->number . '.');
	}

	public function changeStatus(ChangeReviewStatusRequest $request, Project $project, ReviewDiscussionService $discussion): JsonResponse|RedirectResponse
	{
		$thread = $request->thread();

		try {
			$updated = $discussion->changeStatus($project, $thread, $request->target(), (int) $request->validated('version'), Staff::current());
		} catch (ReviewConflictException $e) {
			return $request->expectsJson()
				? response()->json(['message' => $e->getMessage(), 'current' => $e->current], 409)
				: back()->withErrors(['status' => $e->getMessage()], 'thread' . $thread->id);
		}

		return $request->expectsJson()
			? response()->json(['status' => $updated->status->value, 'version' => $updated->lock_version])
			: redirect()->to($this->threadUrl($request, $project, $thread))->with('status', 'Uwaga ' . $thread->number . ': ' . $updated->status->label() . '.');
	}

	/**
	 * Powrót do wątku z zachowaniem filtra listy (tylko znane wartości).
	 */
	private function threadUrl(Request $request, Project $project, ReviewThread $thread): string
	{
		$filter = ReviewThreadStatus::fromFilter($request->input('filter'));

		return route('fmi.design-review.show', array_filter([$project, 'status' => $filter?->filter()])) . '#uwaga-' . $thread->number;
	}

	/**
	 * Jawny adres linku pokazujemy raz, w odpowiedzi na POST (bez przekierowania i bez zapisu w sesji) —
	 * tak jak linki Treści.
	 */
	public function storeLink(StoreReviewLinkRequest $request, Project $project, ClientAccessService $accesses): Response
	{
		$issued = $accesses->generateReview(
			$project,
			Staff::current(),
			null,
			$request->label(),
			$request->boolean('can_comment'),
			$request->boolean('can_approve'),
			$request->code(),
		);

		return response()->view('fillmein.design-review.link-issued', ['project' => $project, 'url' => $issued->url(), 'access' => $issued->access]);
	}

	public function revokeLink(Project $project, int $link, ClientAccessService $accesses): RedirectResponse
	{
		$this->authorize('manageClientAccess', $project);

		$access = $project->clientAccesses()->review()->findOrFail($link);
		$accesses->revoke($access, Staff::current());

		return redirect()->route('fmi.design-review.show', $project)->with('status', 'Link recenzji został unieważniony.');
	}

	public function storeDecision(StoreReviewDecisionRequest $request, Project $project, ReviewDecisionService $decisions): JsonResponse|RedirectResponse
	{
		$revision = $project->reviewRevisions()->where('number', (int) $request->validated('revision'))->firstOrFail();

		try {
			$decision = $decisions->decide($project, $revision, $request->decision(), $request->validated('note'), $request->validated('request_id'), Staff::current());
		} catch (ReviewConflictException $e) {
			return $request->expectsJson()
				? response()->json(['message' => $e->getMessage(), 'current' => $e->current], 409)
				: redirect()->to(route('fmi.design-review.show', $project) . '#decyzja')->withErrors(['decision' => $e->getMessage()], 'decision');
		}

		return $request->expectsJson()
			? response()->json(['decision' => $decision->decision->value, 'revision' => $decision->revision_number, 'version_status' => $decision->version_status], 201)
			: redirect()->to(route('fmi.design-review.show', $project) . '#decyzja')->with('status', 'Zapisano decyzję dla ' . mb_strtolower($revision->title) . ': ' . mb_strtolower($decision->decision->label()) . '.');
	}

	public function createRevision(Project $project, ReviewService $reviews): RedirectResponse
	{
		$this->authorize('manageReviews', $project);

		try {
			$revision = $reviews->createRevision($project, Staff::current());
		} catch (InvalidArgumentException $e) {
			return back()->withErrors(['revision' => $e->getMessage()], 'decision');
		}

		return redirect()->to(route('fmi.design-review.show', $project) . '#decyzja')->with('status', 'Utworzono ' . mb_strtolower($revision->title) . ' — czeka na decyzję.');
	}

	/**
	 * Czy plik w Figmie zmienił się od decyzji (ten sam link, inna wersja) — odczyt z API przez połączenie OAuth.
	 */
	public function checkDecision(Project $project, int $decision, ReviewDecisionService $decisions): RedirectResponse
	{
		$this->authorize('manageReviews', $project);

		$model = ReviewDecision::query()->where('project_id', $project->id)->findOrFail($decision);
		$result = $decisions->compare($model);

		return redirect()->to(route('fmi.design-review.show', $project) . '#decyzja')->with('version_check', [
			'decision' => $model->id,
			'status' => $result['status'],
			'last_modified' => $result['last_modified']?->format('d.m.Y H:i'),
		]);
	}
}
