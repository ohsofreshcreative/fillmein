<?php

namespace App\Http\Controllers\App;

use App\Exceptions\PageStructureException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DuplicatePageRequest;
use App\Http\Requests\ReorderPagesRequest;
use App\Http\Requests\StorePageRequest;
use App\Http\Requests\UpdatePageRequest;
use App\Integrations\Figma\FigmaException;
use App\Models\Page;
use App\Models\Project;
use App\Services\FigmaFrameService;
use App\Services\PageDuplicationService;
use App\Services\PageService;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Podstrony projektu (/app/projects/{project}/pages).
 *
 * Podstrona jest zawsze wiązana w zakresie projektu (`scopeBindings` na grupie tras),
 * więc identyfikator z adresu nie daje dostępu do podstrony innego projektu.
 * Autoryzacja: ProjectPolicy@managePages (w FormRequestach) oraz @view dla odczytu.
 */
class PageController extends Controller
{
	public function __construct(
		private PageService $pages,
		private PageDuplicationService $duplication,
		private FigmaFrameService $figma,
	) {
	}

	public function edit(Project $project, Page $page): View
	{
		$this->authorize('managePages', $project);

		$siblings = $project->pages()->orderBy('position')->orderBy('id')->get();
		$figmaFrame = $this->figma->frameFor($page);

		return view('fillmein.projects.pages.edit', [
			'project' => $project,
			'page' => $page,
			'parentOptions' => $this->parentOptions($siblings, $page),
			'sectionsCount' => $page->sections()->whereNull('archived_at')->count(),
			'childrenCount' => $page->children()->whereNull('archived_at')->count(),
			'figmaFrame' => $figmaFrame,
			'figmaPreviews' => $figmaFrame?->previews()->get() ?? collect(),
			'figmaConfigured' => $this->figma->isConfigured(),
		]);
	}

	/**
	 * Zapis bez czekania na Figmę: frame i podglądy pobiera w tle kolejka na liście podstron
	 * (FigmaQueueController), więc można od razu dodawać kolejne podstrony. Z JS odpowiedź jest w JSON.
	 */
	public function store(StorePageRequest $request, Project $project): RedirectResponse|JsonResponse
	{
		try {
			$page = $this->pages->create($project, $request->pageData(), Staff::current());
		} catch (PageStructureException $e) {
			if ($request->expectsJson()) {
				return response()->json(['message' => $e->getMessage(), 'errors' => [$e->field => [$e->getMessage()]]], 422);
			}

			return back()->withInput()->withErrors([$e->field => $e->getMessage()]);
		}

		$queued = $page->figma_frame_url && $this->figma->isConfigured();
		$message = 'Podstrona „' . $page->name . '” została dodana.' . ($queued ? ' Frame z Figmy pobiera się w tle.' : '');

		if ($request->expectsJson()) {
			return response()->json([
				'id' => $page->id,
				'name' => $page->name,
				'message' => $message,
				'queued' => $queued,
				'fetch_url' => $queued ? route('fmi.projects.pages.figma.queue.fetch', [$project, $page]) : null,
				'previews_url' => $queued ? route('fmi.projects.pages.figma.queue.previews', [$project, $page]) : null,
				'edit_url' => route('fmi.projects.pages.edit', [$project, $page]),
			], 201);
		}

		return redirect()->route('fmi.projects.show', $project)->with('status', $message);
	}

	public function update(UpdatePageRequest $request, Project $project, Page $page): RedirectResponse
	{
		try {
			$this->pages->update($page, $request->pageData(), Staff::current());
		} catch (PageStructureException $e) {
			return back()->withInput()->withErrors([$e->field => $e->getMessage()]);
		}

		return $this->withFrame(redirect()->route('fmi.projects.show', $project), $page->refresh(), 'Podstrona została zapisana.');
	}

	/**
	 * Po zapisie z linkiem frame'u pobieramy go od razu (bez osobnego „Pobierz z Figmy”). Świeży cache nie odpytuje API.
	 * Błąd Figmy nie cofa zapisu podstrony — trafia do komunikatu, a pobranie można powtórzyć ręcznie.
	 */
	private function withFrame(RedirectResponse $redirect, Page $page, string $message): RedirectResponse
	{
		if (! $page->figma_frame_url || ! $this->figma->isConfigured()) {
			return $redirect->with('status', $message);
		}

		try {
			$result = $this->figma->fetch($page, Staff::current());
		} catch (FigmaException $e) {
			return $redirect->with('status', $message)->with('error', 'Nie udało się pobrać frame\'u z Figmy: ' . $e->getMessage());
		}

		if ($result['previews_failed'] > 0) {
			return $redirect->with('status', $message)->with('error', 'Frame pobrany, ale ' . $result['previews_failed'] . ' podglądów się nie pobrało — użyj „Pobierz z Figmy” na podstronie.');
		}

		return $redirect->with('status', $message . ($result['from_cache'] ? '' : ' Pobrano frame „' . ($result['frame']->node_name ?? $result['frame']->node_id) . '” z Figmy.'));
	}

	public function duplicate(DuplicatePageRequest $request, Project $project, Page $page): RedirectResponse
	{
		try {
			$copy = $this->duplication->duplicate($page, $request->options(), Staff::current());
		} catch (PageStructureException $e) {
			return back()->withErrors([$e->field => $e->getMessage()])->with('error', $e->getMessage());
		}

		return redirect()
			->route('fmi.projects.show', $project)
			->with('status', 'Utworzono duplikat „' . $copy->name . '”.');
	}

	public function archive(Project $project, Page $page): RedirectResponse
	{
		$this->authorize('managePages', $project);

		$this->pages->archive($page, Staff::current());

		return back()->with('status', 'Podstrona „' . $page->name . '” została zarchiwizowana.');
	}

	public function restore(Project $project, Page $page): RedirectResponse
	{
		$this->authorize('managePages', $project);

		try {
			$this->pages->restore($page, Staff::current());
		} catch (PageStructureException $e) {
			return back()->with('error', $e->getMessage());
		}

		return back()->with('status', 'Podstrona „' . $page->name . '” została przywrócona.');
	}

	public function reorder(ReorderPagesRequest $request, Project $project): RedirectResponse
	{
		try {
			$this->pages->reorder($project, $request->parentId(), $request->order(), Staff::current());
		} catch (PageStructureException $e) {
			return back()->with('error', $e->getMessage());
		}

		return back()->with('status', 'Kolejność podstron została zapisana.');
	}

	/**
	 * Możliwi rodzice: aktywne podstrony projektu bez edytowanej podstrony i jej potomków
	 * (inaczej dałoby się utworzyć cykl).
	 *
	 * @return array<int, string>
	 */
	private function parentOptions($pages, Page $page): array
	{
		$excluded = $this->pages->subtreeIds($page);
		$options = [];

		$walk = function (array $nodes, int $depth) use (&$walk, &$options, $excluded): void {
			foreach ($nodes as $node) {
				if (in_array((int) $node['page']->id, $excluded, true)) {
					continue;
				}

				$options[$node['page']->id] = str_repeat('— ', $depth) . $node['page']->name;

				$walk($node['children'], $depth + 1);
			}
		};

		$walk(Page::tree(collect($pages)->reject(fn (Page $p) => $p->isArchived())), 0);

		return $options;
	}
}
