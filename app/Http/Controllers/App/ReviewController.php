<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewContentRequest;
use App\Models\Project;
use App\Services\ClientEditorService;
use App\Services\ContentReviewService;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Przegląd treści projektu przez zespół. Autoryzacja: ProjectPolicy@editContent.
 */
class ReviewController extends Controller
{
	public function __construct(private ContentReviewService $review, private ClientEditorService $editor)
	{
	}

	public function index(Request $request, Project $project): View
	{
		$this->authorize('editContent', $project);

		$perPage = (int) config('fillmein.projects.per_page', 20);
		$pages = $this->editor->pages($project);
		$filters = [
			'strona' => $pages->contains('id', $request->integer('strona')) ? $request->integer('strona') : null,
			'status' => array_key_exists((string) $request->query('status'), ContentReviewService::STATUSES) ? (string) $request->query('status') : '',
			'autor' => array_key_exists((string) $request->query('autor'), ContentReviewService::AUTHORS) ? (string) $request->query('autor') : '',
		];

		$result = $this->review->list($project, $filters);
		$total = $result['items']->count();
		$lastPage = max(1, (int) ceil($total / $perPage));
		$page = min(max(1, $request->integer('page', 1)), $lastPage);

		return view('fillmein.projects.review', [
			'project' => $project,
			'items' => $result['items']->forPage($page, $perPage)->values(),
			'counts' => $result['counts'],
			'filters' => $filters,
			'pages' => $pages->pluck('name', 'id')->all(),
			'total' => $total,
			'page' => $page,
			'lastPage' => $lastPage,
		]);
	}

	public function mark(ReviewContentRequest $request, Project $project, int $value): RedirectResponse
	{
		$this->review->mark($project, $value, (string) $request->validated('note'), Staff::current());

		return back()->with('status', 'Oznaczono treść do poprawy.');
	}

	public function clear(ReviewContentRequest $request, Project $project, int $value): RedirectResponse
	{
		$this->review->clear($project, $value, Staff::current());

		return back()->with('status', 'Zdjęto oznaczenie.');
	}
}
