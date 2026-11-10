<?php

namespace App\Http\Controllers\App;

use App\Exceptions\FigmaImportException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApplyFigmaImportRequest;
use App\Http\Requests\UpdateFigmaNodeRuleRequest;
use App\Models\Page;
use App\Models\Project;
use App\Services\FigmaImportService;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Analiza warstw frame'u i import sekcji/pól: podgląd zmian, wykluczenia, zatwierdzenie.
 * Podstrona jest wiązana w zakresie projektu (`scopeBindings`).
 */
class FigmaImportController extends Controller
{
	public function __construct(private FigmaImportService $import)
	{
	}

	public function show(Project $project, Page $page): View
	{
		$this->authorize('configureFields', $project);

		try {
			$plan = $this->import->plan($page);
		} catch (FigmaImportException $e) {
			$plan = null;
			$error = $e->getMessage();
		}

		return view('fillmein.projects.pages.figma-import', [
			'project' => $project,
			'page' => $page,
			'plan' => $plan,
			'planError' => $error ?? null,
			'previews' => $plan ? $plan['frame']->previews()->downloadable()->pluck('node_id')->flip() : collect(),
		]);
	}

	public function apply(ApplyFigmaImportRequest $request, Project $project, Page $page): RedirectResponse
	{
		try {
			$counts = $this->import->apply($page, Staff::current(), $request->fingerprint(), $request->selection());
		} catch (FigmaImportException $e) {
			return redirect()->route('fmi.projects.pages.figma.import', [$project, $page])->with('error', $e->getMessage());
		}

		return redirect()
			->route('fmi.projects.pages.figma.import', [$project, $page])
			->with('status', sprintf(
				'Import zatwierdzony: nowe %d, zaktualizowane %d, przeniesione %d, przywrócone %d, do archiwum %d.',
				$counts['create'],
				$counts['update'] - $counts['moved'],
				$counts['moved'],
				$counts['restore'],
				$counts['archive'],
			));
	}

	public function rule(UpdateFigmaNodeRuleRequest $request, Project $project, Page $page): RedirectResponse
	{
		try {
			$this->import->setRule($page, $request->node(), $request->action(), Staff::current());
		} catch (FigmaImportException $e) {
			return redirect()->route('fmi.projects.pages.figma.import', [$project, $page])->with('error', $e->getMessage());
		}

		$message = match ($request->action()) {
			'include' => 'Warstwa zostanie uwzględniona w imporcie.',
			'exclude' => 'Warstwa zostanie pominięta w imporcie.',
			default => 'Przywrócono automatyczne reguły dla warstwy.',
		};

		return redirect()
			->route('fmi.projects.pages.figma.import', [$project, $page])
			->with('status', $message . ' Sprawdź podgląd i zatwierdź import.');
	}
}
