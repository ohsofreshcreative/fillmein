<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSiteMappingRequest;
use App\Http\Requests\SiteMappingActionRequest;
use App\Http\Requests\UploadSiteSchemaRequest;
use App\Models\Page;
use App\Models\Project;
use App\Services\SiteMappingService;
use App\Support\Staff;
use Illuminate\Http\RedirectResponse;

/**
 * Schemat strony WordPress i dopasowanie do niego (ekran: MappingController@edit). Autoryzacja: ProjectPolicy@export.
 */
class SiteMappingController extends Controller
{
	public function __construct(private SiteMappingService $site)
	{
	}

	public function upload(UploadSiteSchemaRequest $request, Project $project): RedirectResponse
	{
		$replaced = $this->site->schema($project) !== null;
		$this->site->upload($project, (string) file_get_contents($request->file('schema')->getRealPath()), Staff::current());

		return redirect()
			->route('fmi.projects.mapping', $project)
			->with('status', $replaced
				? 'Wgrano nową wersję schematu. Ręczne poprawki zostały, automat dopasował resztę.'
				: 'Wgrano schemat strony i dopasowano projekt automatycznie.');
	}

	public function auto(SiteMappingActionRequest $request, Project $project): RedirectResponse
	{
		if (! $this->site->schema($project)) {
			return redirect()->route('fmi.projects.mapping', $project)->withErrors(['schema' => 'Najpierw wgraj schemat strony.']);
		}

		$this->site->autoMatch($project, Staff::current());

		return redirect()
			->route('fmi.projects.mapping', [$project, 'strona' => $request->integer('strona') ?: null])
			->with('status', 'Dopasowano automatycznie. Ręczne poprawki zostały bez zmian.');
	}

	public function update(SaveSiteMappingRequest $request, Project $project, Page $page): RedirectResponse
	{
		$count = $this->site->saveManual($project, $page, $request->validated(), Staff::current());

		return redirect()
			->route('fmi.projects.mapping', [$project, 'strona' => $page->id])
			->with('status', $count ? 'Zapisano ręczne dopasowanie.' : 'Bez zmian.');
	}

	public function restore(SiteMappingActionRequest $request, Project $project, Page $page): RedirectResponse
	{
		[$type, $id] = $request->restoreTarget() ?? abort(422);
		$this->site->restore($project, $page, $type, $id, Staff::current());

		return redirect()
			->route('fmi.projects.mapping', [$project, 'strona' => $page->id])
			->with('status', 'Przywrócono dopasowanie automatyczne.');
	}
}
