<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HandlesContentWrites;
use App\Http\Requests\ClientContentRequest;
use App\Models\Asset;
use App\Models\ClientAccess;
use App\Models\Field;
use App\Models\Page;
use App\Models\Project;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Services\AssetService;
use App\Services\ClientEditorService;
use App\Support\ProjectProgress;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use WP_User;

/**
 * Publiczny edytor klienta. Dostęp rozwiązuje middleware `fmi.client`; kontroler pracuje wyłącznie
 * na projekcie przypisanym do linku.
 *
 * Widoki nie znają adresu edytora, bo zawierałby token: linki to same parametry (`?strona=`, `?historia=`),
 * a JS buduje adresy zapisu z `location.pathname`.
 */
class EditorController extends Controller
{
	use HandlesContentWrites;

	public function __construct(private ClientEditorService $editor, private AssetService $assets)
	{
	}

	public function show(Request $request): View|BinaryFileResponse|StreamedResponse
	{
		if ($request->filled('plik')) {
			$asset = $this->editor->projectAsset($this->access($request)->project, $request->string('plik')->toString());

			return $this->assets->respond($asset, $request->string('wariant', 'thumb')->toString());
		}

		if ($request->filled('podglad')) {
			return $this->sectionPreview($request);
		}

		if ($request->filled('historia')) {
			return $this->history($request);
		}

		$project = $this->access($request)->project;
		$pages = $this->editor->pages($project);
		$current = $request->filled('strona')
			? $pages->firstWhere('id', $request->integer('strona'))
			: $pages->first();

		abort_if($request->filled('strona') && ! $current, 404);

		$progress = ProjectProgress::forProject($project->id);
		$structure = $current ? $this->editor->structure($current) : null;
		$previews = $current ? $this->editor->sectionPreviews($current, $structure) : collect();

		return view('fillmein.client.editor', [
			'project' => $project,
			'tree' => Page::tree($pages),
			'current' => $current,
			'structure' => $structure,
			'previews' => $previews,
			'highlights' => $current ? $this->editor->fieldHighlights($current, $structure, $previews) : [],
			'notes' => $structure ? $this->editor->sectionNotes($structure) : collect(),
			'previewUrl' => fn (Section $section) => '?podglad=' . $section->id,
			'progress' => $progress,
			'historyUrl' => $this->historyLink(),
			'assetUrl' => $this->assetLink(),
		]);
	}

	public function save(ClientContentRequest $request, int $field): JsonResponse
	{
		return $this->writeValue($request, $field);
	}

	public function restore(ClientContentRequest $request, int $field, int $revision): JsonResponse
	{
		return $this->writeRestore($request, $field, $revision);
	}

	public function uploadAsset(ClientContentRequest $request, int $field): JsonResponse
	{
		return $this->writeUpload($request, $field);
	}

	public function detachAsset(ClientContentRequest $request, int $field, string $asset): JsonResponse
	{
		return $this->writeDetach($request, $field, $asset);
	}

	public function reorderAssets(ClientContentRequest $request, int $field): JsonResponse
	{
		return $this->writeAssetOrder($request, $field);
	}

	public function saveSectionNote(ClientContentRequest $request, int $section): JsonResponse
	{
		return $this->writeSectionNote($request, $section);
	}

	public function saveMediaLink(ClientContentRequest $request, int $field): JsonResponse
	{
		return $this->writeMediaLink($request, $field);
	}

	public function updateAlt(ClientContentRequest $request, int $field, string $asset): JsonResponse
	{
		return $this->writeAlt($request, $field, $asset);
	}

	public function addRow(ClientContentRequest $request, int $field): JsonResponse
	{
		return $this->writeNewRow($request, $field);
	}

	public function removeRow(ClientContentRequest $request, int $field, string $row): JsonResponse
	{
		return $this->writeRowRemoval($request, $field, $row);
	}

	public function reorderRows(ClientContentRequest $request, int $field): JsonResponse
	{
		return $this->writeRowOrder($request, $field);
	}

	protected function who(ClientContentRequest $request): ClientAccess|WP_User
	{
		return $this->access($request);
	}

	protected function project(ClientContentRequest $request): Project
	{
		return $this->access($request)->project;
	}

	protected function forStaff(): bool
	{
		return false;
	}

	/**
	 * Klient nie korzysta z historii pola; przywracanie wersji zostaje w panelu.
	 */
	protected function historyLink(): ?Closure
	{
		return null;
	}

	protected function assetLink(): Closure
	{
		return fn (Asset $asset, string $variant) => '?' . http_build_query(['plik' => $asset->uuid, 'wariant' => $variant]);
	}

	private function sectionPreview(Request $request): StreamedResponse
	{
		$preview = $this->editor->sectionPreview($this->access($request)->project, $request->integer('podglad'));

		return Storage::disk('fmi')->response($preview->path, null, [
			'Content-Type' => $preview->mime_type ?: 'image/png',
			'Cache-Control' => 'private, max-age=300',
			'X-Content-Type-Options' => 'nosniff',
		], 'inline');
	}

	private function history(Request $request): View
	{
		$project = $this->access($request)->project;
		$field = $this->editor->visibleField($project, $request->integer('historia'));

		try {
			$row = $this->editor->contextRow($field, $request->query('wiersz') ?: null);
		} catch (ValidationException) {
			abort(404);
		}

		return view('fillmein.client.history', $this->editor->history($field, $row) + [
			'project' => $project,
			'field' => $field,
			'row' => $row,
			'editor' => $this->editor,
			'backUrl' => '?' . http_build_query(['strona' => $field->section->page_id]),
		]);
	}

	private function access(Request $request): ClientAccess
	{
		return $request->attributes->get('clientAccess');
	}
}
