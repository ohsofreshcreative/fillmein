<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\FetchFigmaFrameRequest;
use App\Integrations\Figma\FigmaException;
use App\Integrations\Figma\FigmaUrl;
use App\Models\Page;
use App\Models\Project;
use App\Services\FigmaFrameService;
use App\Support\Staff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Frame Figmy podstrony: pobranie z API i podglądy z prywatnego cache.
 *
 * Podstrona jest wiązana w zakresie projektu (`scopeBindings`), a podgląd wyszukiwany
 * wyłącznie wśród warstw frame'u tej podstrony — ID warstwy z adresu nie daje dostępu do innych.
 */
class FigmaController extends Controller
{
	public function __construct(private FigmaFrameService $frames)
	{
	}

	public function fetch(FetchFigmaFrameRequest $request, Project $project, Page $page): RedirectResponse
	{
		$this->frames->limitTime((float) config('fillmein.figma.request_budget_seconds', 20));

		try {
			$result = $this->frames->fetch($page, Staff::current(), $request->force());
		} catch (FigmaException $e) {
			return back()->with('error', $e->getMessage());
		}

		if ($result['from_cache']) {
			return back()->with('status', 'Dane frame\'u są aktualne (pobrane ' . $result['frame']->fetched_at->diffForHumans() . ').');
		}

		$message = 'Pobrano frame „' . ($result['frame']->node_name ?? $result['frame']->node_id) . '” z Figmy.';

		if ($result['previews_remaining'] > 0) {
			$message .= ' Pozostałe podglądy (' . $result['previews_remaining'] . ') pobiorą się przy pierwszym wyświetleniu.';
		}

		if ($result['previews_failed'] > 0) {
			return back()->with('error', $message . ' Nie udało się pobrać ' . $result['previews_failed'] . ' podglądów — spróbuj ponownie.');
		}

		return back()->with('status', $message);
	}

	public function preview(Project $project, Page $page, string $node): StreamedResponse
	{
		$this->authorize('view', $project);

		$nodeId = FigmaUrl::decodeNodeId($node);
		$preview = $nodeId ? $this->frames->previewFor($page, $nodeId) : null;

		abort_unless($preview && $this->frames->ensureLocal($preview), 404);

		return Storage::disk('fmi')->response($preview->path, null, [
			'Content-Type' => $preview->mime_type ?: 'image/png',
			'Cache-Control' => 'private, max-age=300',
			'X-Content-Type-Options' => 'nosniff',
		], 'inline');
	}
}
