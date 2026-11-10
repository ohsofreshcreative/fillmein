<?php

namespace App\Http\Middleware;

use App\Models\ClientAccess;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trasa modułu (`fmi.module:content` / `fmi.module:review`) działa tylko w projekcie z włączonym modułem.
 *
 * Moduł to cecha projektu, nie uprawnienie, więc sprawdzamy go poza ProjectPolicy (tam Administrator przechodzi
 * przez `before`). Wyłączony moduł daje 404 — jak brak zasobu, bez zdradzania ustawień projektu.
 * Projekt pochodzi z wiązania trasy (panel) albo z dostępu klienta (`fmi.client` przed tym middleware).
 */
class EnsureProjectModule
{
	public function handle(Request $request, Closure $next, string $module): Response
	{
		$project = $request->route('project');
		$access = $request->attributes->get('clientAccess');

		if (! $project instanceof Project && $access instanceof ClientAccess) {
			$project = $access->project;
		}

		if (! $project instanceof Project) {
			abort(404);
		}

		if (! $project->usesModule($module)) {
			return $access instanceof ClientAccess
				? response()->view('fillmein.client.invalid', [], 404)
				: abort(404);
		}

		return $next($request);
	}
}
