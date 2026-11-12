<?php

namespace App\Http\Controllers\Client;

use App\Exceptions\PageStructureException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientDuplicatePageRequest;
use App\Models\ClientAccess;
use App\Models\Page;
use App\Services\PageDuplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Klient: dodanie kolejnej podstrony na wzór istniejącej (np. następny produkt) i usunięcie dodanej przez siebie.
 * Podstrony szukamy wyłącznie w projekcie linku — obca podstrona daje 404.
 */
class PageController extends Controller
{
	public function __construct(private PageDuplicationService $duplication)
	{
	}

	public function duplicate(ClientDuplicatePageRequest $request, int $page): RedirectResponse
	{
		$token = $this->token($request);
		$access = $this->access($request);
		$source = $this->page($access, $page);

		try {
			$copy = $this->duplication->duplicateForClient($source, $access, $request->validated()['name']);
		} catch (PageStructureException $e) {
			return $this->editor($token, $source->id)->with('error', $e->getMessage());
		}

		return $this->editor($token, $copy->id)->with('status', 'Dodano podstronę „' . $copy->name . '”. Uzupełnij jej treść poniżej.');
	}

	public function archive(Request $request, int $page): RedirectResponse
	{
		$token = $this->token($request);
		$access = $this->access($request);
		$target = $this->page($access, $page);

		try {
			$this->duplication->archiveForClient($target, $access);
		} catch (PageStructureException $e) {
			return $this->editor($token, $target->id)->with('error', $e->getMessage());
		}

		return $this->editor($token, $target->duplicated_from_id)->with('status', 'Usunięto podstronę „' . $target->name . '”.');
	}

	private function page(ClientAccess $access, int $id): Page
	{
		return $access->project->pages()->whereNull('archived_at')->whereKey($id)->firstOrFail();
	}

	private function editor(string $token, ?int $page): RedirectResponse
	{
		return redirect()->route('fmi.client.editor', array_filter(['token' => $token, 'strona' => $page]));
	}

	/**
	 * Middleware `fmi.client` zdejmuje token z parametrów trasy; do przekierowania bierzemy go z adresu
	 * (/p/{token}/…) — nie trafia do HTML ani dziennika.
	 */
	private function token(Request $request): string
	{
		return (string) $request->segment(2);
	}

	private function access(Request $request): ClientAccess
	{
		return $request->attributes->get('clientAccess');
	}
}
