<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientAccessRequest;
use App\Models\ClientAccess;
use App\Models\Project;
use App\Services\ClientAccessService;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Linki klienta w panelu projektu. Autoryzacja: ProjectPolicy@manageClientAccess.
 *
 * Jawny adres pokazujemy w odpowiedzi na POST, bez przekierowania: `with()` zapisałoby token w pliku sesji.
 * Po opuszczeniu tej strony adresu nie da się odzyskać.
 */
class ClientAccessController extends Controller
{
	public function __construct(private ClientAccessService $accesses)
	{
	}

	public function store(StoreClientAccessRequest $request, Project $project): RedirectResponse
	{
		$this->accesses->generate($project, Staff::current(), null, $request->label());

		// Adres jest widoczny na stałe w karcie „Linki dla klienta”, więc wracamy do projektu.
		return redirect()->to(route('fmi.projects.show', $project) . '#linki-klienta')->with('status', 'Link dla klienta został wygenerowany.');
	}

	public function regenerate(StoreClientAccessRequest $request, Project $project): RedirectResponse
	{
		$this->accesses->regenerate($project, Staff::current(), null, $request->label());

		return redirect()->to(route('fmi.projects.show', $project) . '#linki-klienta')->with('status', 'Wygenerowano nowy link dla klienta — poprzedni przestał działać.');
	}

	public function revoke(Project $project, ClientAccess $clientAccess): RedirectResponse
	{
		$this->authorize('manageClientAccess', $project);
		abort_unless($clientAccess->project_id === $project->id && $clientAccess->scope === ClientAccess::SCOPE_CONTENT, 404);

		$this->accesses->revoke($clientAccess, Staff::current());

		return redirect()->route('fmi.projects.show', $project)->with('status', 'Link klienta został unieważniony.');
	}
}
