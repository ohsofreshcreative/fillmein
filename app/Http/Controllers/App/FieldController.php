<?php

namespace App\Http\Controllers\App;

use App\Enums\FieldType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderStructureRequest;
use App\Http\Requests\SaveFieldRequest;
use App\Models\Field;
use App\Models\Page;
use App\Models\Project;
use App\Models\Section;
use App\Services\SectionService;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pola, grupy i repeatery podstrony. Pole jest wiązane w zakresie podstrony (`Page::fields`),
 * a pole nadrzędne i listy kolejności sprawdza SectionService. Autoryzacja: ProjectPolicy@configureFields.
 */
class FieldController extends Controller
{
	use HandlesFieldConfig;

	public function __construct(private SectionService $sections)
	{
	}

	public function create(Request $request, Project $project, Page $page, Section $section): View
	{
		$this->authorize('configureFields', $project);

		$parent = $request->filled('parent')
			? Field::query()->where('section_id', $section->id)->whereKey((int) $request->query('parent'))->firstOrFail()
			: null;

		return view('fillmein.projects.pages.structure.field', $this->formData($project, $page, $section, $parent, null));
	}

	public function edit(Project $project, Page $page, Field $field): View
	{
		$this->authorize('configureFields', $project);

		return view('fillmein.projects.pages.structure.field', $this->formData($project, $page, $field->section, $field->parent, $field));
	}

	public function store(SaveFieldRequest $request, Project $project, Page $page, Section $section): RedirectResponse
	{
		$field = $this->configuring(fn () => $this->sections->createField($section, $request->fieldData(), Staff::current()));

		return $this->toStructure($project, $page, 'Pole „' . $field->label . '” zostało dodane.', 'pole-' . $field->id);
	}

	public function update(SaveFieldRequest $request, Project $project, Page $page, Field $field): RedirectResponse
	{
		$this->configuring(fn () => $this->sections->updateField($field, $request->fieldData(), Staff::current()));

		return $this->toStructure($project, $page, 'Pole zostało zapisane.', 'pole-' . $field->id);
	}

	public function archive(Project $project, Page $page, Field $field): RedirectResponse
	{
		$this->authorize('configureFields', $project);

		$this->sections->archiveField($field, Staff::current());

		return $this->toStructure($project, $page, 'Pole „' . $field->label . '” zostało zarchiwizowane.');
	}

	public function restore(Project $project, Page $page, Field $field): RedirectResponse
	{
		$this->authorize('configureFields', $project);

		$this->configuring(fn () => $this->sections->restoreField($field, Staff::current()));

		return $this->toStructure($project, $page, 'Pole „' . $field->label . '” zostało przywrócone.', 'pole-' . $field->id);
	}

	public function reorder(ReorderStructureRequest $request, Project $project, Page $page, Section $section): JsonResponse|RedirectResponse
	{
		$this->configuring(fn () => $this->sections->reorderFields($section, $request->parentId(), $request->order(), Staff::current()));

		return $request->expectsJson()
			? response()->json(['message' => 'Kolejność pól została zapisana.'])
			: $this->toStructure($project, $page, 'Kolejność pól została zapisana.');
	}

	private function formData(Project $project, Page $page, Section $section, ?Field $parent, ?Field $field): array
	{
		return [
			'project' => $project,
			'page' => $page,
			'section' => $section,
			'parent' => $parent,
			'field' => $field,
			'types' => collect(FieldType::cases())->mapWithKeys(fn (FieldType $type) => [$type->value => $type->label()])->all(),
			// Typy, dla których formularz pokazuje dane ustawienie (FieldType::settingKeys).
			'settingTypes' => collect(FieldType::cases())
				->flatMap(fn (FieldType $type) => array_map(fn ($key) => [$key, $type->value], $type->settingKeys()))
				->groupBy(0)
				->map(fn ($rows) => $rows->pluck(1)->all())
				->all(),
		];
	}

	private function toStructure(Project $project, Page $page, string $status, ?string $anchor = null): RedirectResponse
	{
		// Dodawanie z ekranu „Analiza i import pól” wraca na ten ekran (return=import), nie do struktury.
		$url = request()->input('return') === 'import'
			? route('fmi.projects.pages.figma.import', [$project, $page])
			: route('fmi.projects.pages.structure', [$project, $page]);

		return redirect()->to($anchor ? $url . '#' . $anchor : $url)->with('status', $status);
	}
}
