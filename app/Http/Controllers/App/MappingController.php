<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveMappingRequest;
use App\Models\Page;
use App\Models\Project;
use App\Services\AcfMappingService;
use App\Services\ClientEditorService;
use App\Services\SiteMappingService;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Mapowanie podstron i pól na WordPress oraz ACF. Autoryzacja: ProjectPolicy@export.
 */
class MappingController extends Controller
{
	public function __construct(private AcfMappingService $mapping, private ClientEditorService $editor, private SiteMappingService $site)
	{
	}

	public function edit(Request $request, Project $project): View
	{
		$this->authorize('export', $project);

		$pages = $this->editor->pages($project);
		$page = $pages->firstWhere('id', $request->integer('strona')) ?? $pages->first();

		// Ze schematem strony WordPress: przegląd dopasowania zamiast ręcznych nazw ACF.
		if ($resolved = $this->site->resolve($project)) {
			return view('fillmein.projects.site-mapping', [
				'project' => $project,
				'resolved' => $resolved,
				'pages' => $pages->map(fn (Page $item) => ['page' => $item, 'counts' => SiteMappingService::counts($resolved, $item->id)])->all(),
				'page' => $page,
				'form' => $page ? $this->site->form($page, $resolved) : null,
			]);
		}

		return view('fillmein.projects.mapping', [
			'project' => $project,
			'pages' => $pages->pluck('name', 'id')->all(),
			'page' => $page,
			'form' => $page ? $this->mapping->form($page) : null,
		]);
	}

	public function update(SaveMappingRequest $request, Project $project, Page $page): RedirectResponse
	{
		$this->mapping->save($page, $request->validated(), Staff::current());

		return redirect()
			->route('fmi.projects.mapping', [$project, 'strona' => $page->id])
			->with('status', 'Zapisano mapowanie.');
	}
}
