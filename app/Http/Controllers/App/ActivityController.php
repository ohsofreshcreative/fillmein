<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Project;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Dziennik aktywności projektu z filtrami. Autoryzacja: ProjectPolicy@viewActivity.
 * Paginacja ręczna: pakiet illuminate/pagination nie jest zainstalowany w motywie.
 */
class ActivityController extends Controller
{
	private const PER_PAGE = 30;

	public function index(Request $request, Project $project): View
	{
		$this->authorize('viewActivity', $project);

		$pages = $project->pages()->orderBy('position')->orderBy('id')->pluck('name', 'id');
		$actors = $this->actors($project);
		$filters = [
			'typ' => array_key_exists((string) $request->query('typ'), Activity::AREAS) ? (string) $request->query('typ') : '',
			'aktor' => array_key_exists((string) $request->query('aktor'), $actors) ? (string) $request->query('aktor') : '',
			'od' => $this->date($request->query('od')),
			'do' => $this->date($request->query('do')),
			'strona' => $pages->has($request->integer('strona')) ? $request->integer('strona') : null,
		];

		$query = Activity::query()->where('project_id', $project->id)->filtered($filters);
		$total = (clone $query)->count();
		$lastPage = max(1, (int) ceil($total / self::PER_PAGE));
		$page = min(max(1, $request->integer('page', 1)), $lastPage);

		return view('fillmein.projects.activity', [
			'project' => $project,
			'activity' => $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, self::PER_PAGE)->get(),
			'filters' => $filters,
			'actors' => $actors,
			'pages' => $pages->all(),
			'total' => $total,
			'page' => $page,
			'lastPage' => $lastPage,
		]);
	}

	/**
	 * @return array<string, string> wartość filtra => etykieta; tylko aktorzy obecni w dzienniku projektu
	 */
	private function actors(Project $project): array
	{
		$actors = [Activity::ACTOR_CLIENT => 'Klient'];
		$ids = Activity::query()->where('project_id', $project->id)->whereNotNull('user_id')->distinct()->pluck('user_id');

		foreach ($ids as $id) {
			$user = get_userdata((int) $id);
			$actors[(string) $id] = $user ? Staff::fullName($user) : 'Konto usunięte';
		}

		return $actors;
	}

	private function date(mixed $value): string
	{
		$date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

		return $date && $date->format('Y-m-d') === $value ? $value : '';
	}
}
