<?php

namespace App\Http\Controllers\App;

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateExportRequest;
use App\Models\Export;
use App\Models\Page;
use App\Models\Project;
use App\Services\ContentExportBuilder;
use App\Services\ExportService;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Zakładka Eksport modułu Treści: wybór zakresu, podsumowanie, historia, ponowne generowanie i pobieranie plików
 * z prywatnego dysku. Autoryzacja: ProjectPolicy@export. Eksport tylko czyta treści — nie zmienia wartości ani statusów.
 */
class ExportController extends Controller
{
	private const HISTORY_LIMIT = 30;

	/** Pobierany plik → nazwa w katalogu eksportu. */
	private const FILES = ['zip' => 'export.zip', 'content' => 'content.json', 'json' => 'export.json'];

	public function __construct(private ExportService $exports, private ContentExportBuilder $content)
	{
	}

	public function index(Project $project): View
	{
		$this->authorize('export', $project);

		$history = $project->exports()->where('type', ExportType::Json->value)->latest()->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get();
		$pages = $this->exports->pagesFor($project);

		return view('fillmein.projects.exports', [
			'project' => $project,
			'history' => $history,
			'latest' => $history->firstWhere('status', ExportStatus::Completed),
			'pages' => $this->withDepth($pages),
			'summaries' => $this->content->summaries($project, $pages),
		]);
	}

	public function store(GenerateExportRequest $request, Project $project): RedirectResponse
	{
		return $this->finish($project, $this->exports->run($project, Staff::current(), $request->pageUuids()));
	}

	/**
	 * Ten sam zakres co wskazany eksport, na bieżącej treści. Podstrony usunięte od tamtej pory są pomijane.
	 */
	public function regenerate(GenerateExportRequest $request, Project $project, string $export): RedirectResponse
	{
		$record = $this->record($project, $export, false);
		$scope = $record->summary['scope'] ?? ['type' => 'project'];
		$uuids = ($scope['type'] ?? 'project') === 'pages' ? array_column($scope['pages'] ?? [], 'id') : null;

		return $this->finish($project, $this->exports->run($project, Staff::current(), $uuids));
	}

	public function download(Project $project, string $export, string $format): StreamedResponse
	{
		$this->authorize('export', $project);

		abort_unless(isset(self::FILES[$format]), 404);

		$record = $this->record($project, $export, true);
		$disk = Storage::disk('fmi');
		$path = dirname((string) $record->file_path) . '/' . self::FILES[$format];

		abort_unless($disk->exists($path), 404);

		$name = sprintf('fillmein-%s-%s%s.%s', Str::slug($project->name) ?: 'projekt', $record->created_at->format('Ymd-His'), $format === 'content' ? '-content' : '', $format === 'zip' ? 'zip' : 'json');

		return $disk->download($path, $name, ['Cache-Control' => 'private, no-store']);
	}

	private function finish(Project $project, Export $export): RedirectResponse
	{
		$route = redirect()->route('fmi.projects.exports', $project);

		return $export->status === ExportStatus::Completed
			? $route->with('status', 'Eksport gotowy — pobierz paczkę ZIP z listy poniżej.')->with('export_ready', $export->uuid)
			: $route->with('error', $export->error_message);
	}

	private function record(Project $project, string $uuid, bool $completed): Export
	{
		$record = Export::query()
			->where('project_id', $project->id)
			->where('uuid', $uuid)
			->where('type', ExportType::Json->value)
			->when($completed, fn ($query) => $query->where('status', ExportStatus::Completed->value))
			->first();

		abort_if($record === null, 404);

		return $record;
	}

	/**
	 * @param Page[] $pages w kolejności drzewa
	 * @return array<int, array{page: Page, depth: int}>
	 */
	private function withDepth(array $pages): array
	{
		$depth = [];
		$result = [];

		foreach ($pages as $page) {
			$depth[$page->id] = isset($depth[$page->parent_id]) ? $depth[$page->parent_id] + 1 : 0;
			$result[] = ['page' => $page, 'depth' => $depth[$page->id]];
		}

		return $result;
	}
}
