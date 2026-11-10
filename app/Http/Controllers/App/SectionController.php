<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderStructureRequest;
use App\Http\Requests\SaveSectionRequest;
use App\Models\Field;
use App\Models\Page;
use App\Models\Project;
use App\Models\Section;
use App\Services\SectionService;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Konfigurator struktury podstrony: lista sekcji z polami oraz operacje na sekcjach.
 * Podstrona i sekcja są wiązane w zakresie projektu i podstrony (`scopeBindings`).
 * Autoryzacja: ProjectPolicy@configureFields.
 */
class SectionController extends Controller
{
	use HandlesFieldConfig;

	public function __construct(private SectionService $sections)
	{
	}

	public function index(Project $project, Page $page): View
	{
		$this->authorize('configureFields', $project);

		$sections = $page->sections()->orderBy('id')->get();
		$fields = Field::query()
			->whereIn('section_id', $sections->modelKeys())
			->orderBy('position')
			->orderBy('id')
			->get();

		return view('fillmein.projects.pages.structure.index', [
			'project' => $project,
			'page' => $page,
			'sections' => $sections->reject->isArchived()->values(),
			'archivedSections' => $sections->filter->isArchived()->values(),
			'fieldsByParent' => $fields->groupBy(fn (Field $field) => $field->parent_id ?: 'section-' . $field->section_id),
			'archivedFields' => $fields->groupBy('section_id')->map(fn ($group) => $group
				->filter(fn (Field $field) => $field->isArchived() && ! $fields->firstWhere('id', $field->parent_id)?->isArchived())
				->values()),
		]);
	}

	public function edit(Project $project, Page $page, Section $section): View
	{
		$this->authorize('configureFields', $project);

		return view('fillmein.projects.pages.structure.section', compact('project', 'page', 'section'));
	}

	public function store(SaveSectionRequest $request, Project $project, Page $page): RedirectResponse
	{
		$section = $this->sections->create($page, $request->sectionData(), Staff::current());

		return $this->toStructure($project, $page, 'Sekcja „' . $section->name . '” została dodana.', 'sekcja-' . $section->id);
	}

	public function update(SaveSectionRequest $request, Project $project, Page $page, Section $section): RedirectResponse
	{
		$this->sections->update($section, $request->sectionData(), Staff::current());

		return $this->toStructure($project, $page, 'Sekcja została zapisana.', 'sekcja-' . $section->id);
	}

	public function archive(Project $project, Page $page, Section $section): RedirectResponse
	{
		$this->authorize('configureFields', $project);

		$this->sections->archive($section, Staff::current());

		return $this->toStructure($project, $page, 'Sekcja „' . $section->name . '” została zarchiwizowana.');
	}

	public function restore(Project $project, Page $page, Section $section): RedirectResponse
	{
		$this->authorize('configureFields', $project);

		$this->sections->restore($section, Staff::current());

		return $this->toStructure($project, $page, 'Sekcja „' . $section->name . '” została przywrócona.', 'sekcja-' . $section->id);
	}

	public function reorder(ReorderStructureRequest $request, Project $project, Page $page): JsonResponse|RedirectResponse
	{
		$this->configuring(fn () => $this->sections->reorder($page, $request->order(), Staff::current()));

		return $request->expectsJson()
			? response()->json(['message' => 'Kolejność sekcji została zapisana.'])
			: $this->toStructure($project, $page, 'Kolejność sekcji została zapisana.');
	}

	private function toStructure(Project $project, Page $page, string $status, ?string $anchor = null): RedirectResponse
	{
		$url = route('fmi.projects.pages.structure', [$project, $page]);

		return redirect()->to($anchor ? $url . '#' . $anchor : $url)->with('status', $status);
	}
}
