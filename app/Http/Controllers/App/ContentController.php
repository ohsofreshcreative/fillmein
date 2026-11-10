<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HandlesContentWrites;
use App\Http\Requests\ClientContentRequest;
use App\Http\Requests\StaffContentRequest;
use App\Integrations\Figma\FigmaUrl;
use App\Models\Asset;
use App\Models\ClientAccess;
use App\Models\Field;
use App\Models\FigmaPreview;
use App\Models\Page;
use App\Models\Project;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Services\AssetService;
use App\Services\ClientEditorService;
use App\Support\ProjectProgress;
use App\Support\Staff;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use WP_User;

/**
 * Edycja treści podstrony przez pracownika: te same formularze i zapisy co u klienta,
 * plus pola ukryte przed klientem. Autoryzacja: ProjectPolicy@editContent.
 */
class ContentController extends Controller
{
	use HandlesContentWrites;

	public function __construct(private ClientEditorService $editor, private AssetService $assets)
	{
	}

	public function edit(Project $project, Page $page): View
	{
		$this->authorize('editContent', $project);

		$structure = $this->editor->structure($page, true);
		$previews = $this->editor->sectionPreviews($page, $structure);

		return view('fillmein.projects.pages.content', [
			'project' => $project,
			'page' => $page,
			'structure' => $structure,
			'previews' => $previews,
			'highlights' => $this->editor->fieldHighlights($page, $structure, $previews),
			'notes' => $this->editor->sectionNotes($structure),
			'previewUrl' => fn (Section $section, FigmaPreview $preview) => $this->previewLink($project, $page, $preview),
			'progress' => ProjectProgress::forProject($project->id),
			'historyUrl' => $this->historyLink(),
			'assetUrl' => $this->assetLink(),
		]);
	}

	/**
	 * Podgląd podstrony do odczytu: to, co widzi i wypełnia klient, więc bez elementów przed nim ukrytych.
	 */
	public function preview(Project $project, Page $page): View
	{
		$this->authorize('view', $project);
		abort_if($page->isArchived(), 404);

		$structure = $this->editor->structure($page);
		$pages = $this->inReadingOrder($this->editor->pages($project));
		$position = array_search($page->id, array_column($pages, 'id'), true);

		return view('fillmein.projects.pages.preview', [
			'project' => $project,
			'page' => $page,
			'structure' => $structure,
			'previews' => $this->editor->sectionPreviews($page, $structure),
			'previewUrl' => fn (Section $section, FigmaPreview $preview) => $this->previewLink($project, $page, $preview),
			'progress' => ProjectProgress::forProject($project->id),
			'assetUrl' => $this->assetLink(),
			'previous' => $position > 0 ? $pages[$position - 1] : null,
			'next' => $pages[$position + 1] ?? null,
			'canEdit' => Gate::allows('editContent', $project),
		]);
	}

	/**
	 * Plik projektu. Dostęp ma każdy, kto widzi projekt (`view`), także bez prawa edycji.
	 */
	public function asset(Project $project, string $asset, string $variant): BinaryFileResponse
	{
		$this->authorize('view', $project);

		return $this->assets->respond($this->editor->projectAsset($project, $asset), $variant);
	}

	public function history(Request $request, Project $project, Page $page, int $field): View
	{
		$this->authorize('editContent', $project);

		$target = $this->editor->visibleField($project, $field, true);

		try {
			$row = $this->editor->contextRow($target, $request->query('row') ?: null);
		} catch (ValidationException) {
			abort(404);
		}

		return view('fillmein.projects.pages.history', $this->editor->history($target, $row) + [
			'project' => $project,
			'page' => $page,
			'field' => $target,
			'row' => $row,
			'editor' => $this->editor,
		]);
	}

	public function save(StaffContentRequest $request, Project $project, Page $page, int $field): JsonResponse
	{
		return $this->writeValue($request, $field);
	}

	public function restore(StaffContentRequest $request, Project $project, Page $page, int $field, int $revision): JsonResponse
	{
		return $this->writeRestore($request, $field, $revision);
	}

	public function uploadAsset(StaffContentRequest $request, Project $project, Page $page, int $field): JsonResponse
	{
		return $this->writeUpload($request, $field);
	}

	public function detachAsset(StaffContentRequest $request, Project $project, Page $page, int $field, string $asset): JsonResponse
	{
		return $this->writeDetach($request, $field, $asset);
	}

	public function reorderAssets(StaffContentRequest $request, Project $project, Page $page, int $field): JsonResponse
	{
		return $this->writeAssetOrder($request, $field);
	}

	public function saveSectionNote(StaffContentRequest $request, Project $project, Page $page, int $section): JsonResponse
	{
		return $this->writeSectionNote($request, $section);
	}

	public function saveMediaLink(StaffContentRequest $request, Project $project, Page $page, int $field): JsonResponse
	{
		return $this->writeMediaLink($request, $field);
	}

	public function updateAlt(StaffContentRequest $request, Project $project, Page $page, int $field, string $asset): JsonResponse
	{
		return $this->writeAlt($request, $field, $asset);
	}

	public function addRow(StaffContentRequest $request, Project $project, Page $page, int $field): JsonResponse
	{
		return $this->writeNewRow($request, $field);
	}

	public function removeRow(StaffContentRequest $request, Project $project, Page $page, int $field, string $row): JsonResponse
	{
		return $this->writeRowRemoval($request, $field, $row);
	}

	public function reorderRows(StaffContentRequest $request, Project $project, Page $page, int $field): JsonResponse
	{
		return $this->writeRowOrder($request, $field);
	}

	protected function who(ClientContentRequest $request): ClientAccess|WP_User
	{
		return Staff::current();
	}

	protected function project(ClientContentRequest $request): Project
	{
		return $request->route('project');
	}

	protected function forStaff(): bool
	{
		return true;
	}

	private function previewLink(Project $project, Page $page, FigmaPreview $preview): string
	{
		return route('fmi.projects.pages.figma.preview', [$project, $page, FigmaUrl::encodeNodeId($preview->node_id)]);
	}

	/**
	 * Podstrony w kolejności drzewa: rodzic, potem jego podstrony.
	 *
	 * @return Page[]
	 */
	private function inReadingOrder(iterable $pages): array
	{
		$walk = function (array $nodes) use (&$walk): array {
			$flat = [];

			foreach ($nodes as $node) {
				array_push($flat, $node['page'], ...$walk($node['children']));
			}

			return $flat;
		};

		return $walk(Page::tree($pages));
	}

	protected function assetLink(): Closure
	{
		$project = request()->route()->parameter('project');

		return fn (Asset $asset, string $variant) => route('fmi.projects.assets.show', [$project, $asset->uuid, $variant]);
	}

	protected function historyLink(): Closure
	{
		$route = request()->route();

		return fn (Field $target, ?RepeaterRow $row) => route('fmi.projects.pages.content.history', array_filter([
			'project' => $route->parameter('project'),
			'page' => $route->parameter('page'),
			'field' => $target->id,
			'row' => $row?->uuid,
		]));
	}
}
