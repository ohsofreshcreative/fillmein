<?php

namespace App\Http\Controllers\App;

use App\Enums\ProjectModule;
use App\Enums\ProjectStatus;
use App\Exceptions\UserManagementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteProjectsRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\SyncProjectMembersRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Requests\UpdateProjectStatusRequest;
use App\Models\Page;
use App\Models\Project;
use App\Services\ProjectAssignmentService;
use App\Services\ProjectService;
use App\Services\SiteMappingService;
use App\Support\ProjectProgress;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
	/**
	 * Dozwolone sortowania: klucz żądania => [kolumna, domyślny kierunek].
	 * Lista zamknięta, żeby parametr z adresu nie trafiał do zapytania SQL.
	 */
	private const SORTS = [
		'updated' => ['updated_at', 'desc'],
		'created' => ['created_at', 'desc'],
		'name' => ['name', 'asc'],
		'client' => ['client_name', 'asc'],
		'status' => ['status', 'asc'],
	];

	public function __construct(
		private ProjectService $projects,
		private ProjectAssignmentService $assignments,
	) {
	}

	/**
	 * Paginacja ręczna: pakiet illuminate/pagination nie jest zainstalowany w motywie.
	 */
	public function index(Request $request): View
	{
		$this->authorize('viewAny', Project::class);

		$perPage = (int) config('fillmein.projects.per_page', 20);
		$filters = $this->filters($request);

		$query = $this->filteredQuery($filters);

		$total = (clone $query)->count();
		$lastPage = max(1, (int) ceil($total / $perPage));
		$page = min(max(1, $request->integer('page', 1)), $lastPage);

		[$column, $direction] = self::SORTS[$filters['sort']];

		$projects = $query
			->orderBy($column, $filters['dir'] ?: $direction)
			->orderBy('id', 'desc')
			->forPage($page, $perPage)
			->withCount(['pages' => fn (Builder $pages) => $pages->whereNull('archived_at')])
			->get();

		return view('fillmein.projects.index', [
			'projects' => $projects,
			'progress' => ProjectProgress::forProjects($projects),
			'people' => $this->peopleFor($projects),
			'filters' => $filters,
			'statuses' => collect(ProjectStatus::cases())->mapWithKeys(fn (ProjectStatus $s) => [$s->value => $s->label()])->all(),
			'sorts' => [
				'updated' => 'Ostatnia aktualizacja',
				'created' => 'Data utworzenia',
				'name' => 'Nazwa projektu',
				'client' => 'Nazwa klienta',
				'status' => 'Status',
			],
			'total' => $total,
			'page' => $page,
			'lastPage' => $lastPage,
			'canCreate' => Gate::allows('create', Project::class),
			'deletable' => $projects->filter(fn (Project $project) => Gate::allows('delete', $project))->mapWithKeys(fn (Project $project) => [$project->id => $project->name]),
			'pageUrl' => fn (int $number) => route('fmi.projects.index', array_filter($filters + ['page' => $number])),
		]);
	}

	public function create(): View
	{
		$this->authorize('create', Project::class);

		return view('fillmein.projects.create', [
			'staff' => $this->staffOptions(),
			'currentUserId' => get_current_user_id(),
		]);
	}

	public function store(StoreProjectRequest $request): RedirectResponse
	{
		$project = $this->projects->create($request->validated(), Staff::current());

		return redirect()
			->route('fmi.projects.show', $project)
			->with('status', 'Projekt został utworzony.');
	}

	public function show(Project $project): View
	{
		$this->authorize('view', $project);

		$project->loadCount([
			'pages' => fn (Builder $query) => $query->whereNull('archived_at'),
			'sections' => fn (Builder $query) => $query->whereNull('archived_at'),
			'fields' => fn (Builder $query) => $query->whereNull('archived_at'),
		]);
		$project->load('members');

		$pages = $project->pages()
			->orderBy('position')
			->orderBy('id')
			->get();

		return view('fillmein.projects.show', [
			'project' => $project,
			'tree' => Page::tree($pages->reject(fn (Page $page) => $page->isArchived())),
			'archivedPages' => $pages->filter(fn (Page $page) => $page->isArchived())->values(),
			'pageOptions' => $this->pageOptions($pages),
			'progress' => ProjectProgress::forProjectIds([$project->id])[$project->id],
			'pageProgress' => ProjectProgress::forProject($project->id)['pages'],
			'author' => $project->author_id ? get_userdata($project->author_id) : null,
			'manager' => $project->manager_id ? get_userdata($project->manager_id) : null,
			'members' => $project->members->map(fn ($member) => get_userdata($member->user_id))->filter()->values(),
			'staff' => $this->staffOptions(),
			'statuses' => collect(ProjectStatus::cases())->mapWithKeys(fn (ProjectStatus $s) => [$s->value => $s->label()])->all(),
			'can' => [
				'update' => Gate::allows('update', $project),
				'managePages' => Gate::allows('managePages', $project),
				'manageMembers' => Gate::allows('manageMembers', $project),
				'delete' => Gate::allows('delete', $project),
				'viewActivity' => Gate::allows('viewActivity', $project),
				'editContent' => Gate::allows('editContent', $project),
				'export' => Gate::allows('export', $project),
				'manageClientAccess' => Gate::allows('manageClientAccess', $project),
			],
			'clientAccesses' => Gate::allows('manageClientAccess', $project)
				? $project->clientAccesses()->content()->latest()->orderByDesc('id')->limit(20)->get()
				: collect(),
			'activity' => Gate::allows('viewActivity', $project)
				? $project->activities()->limit(20)->get()
				: collect(),
		]);
	}

	public function edit(Project $project, SiteMappingService $site): View
	{
		$this->authorize('update', $project);

		return view('fillmein.projects.edit', [
			'project' => $project,
			// Schemat strony WordPress tylko dla osób z eksportem w projektach z modułem Treści.
			'siteSchema' => $project->usesModule(ProjectModule::Content) && Gate::allows('export', $project) ? ['schema' => $site->schema($project)] : null,
			'staff' => $this->staffOptions(),
			'statuses' => collect(ProjectStatus::cases())->mapWithKeys(fn (ProjectStatus $s) => [$s->value => $s->label()])->all(),
		]);
	}

	public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
	{
		try {
			$this->projects->update($project, $request->validated(), Staff::current());
		} catch (UserManagementException $e) {
			return back()->withInput()->withErrors(['manager_id' => $e->getMessage()]);
		}

		return redirect()
			->route('fmi.projects.show', $project)
			->with('status', 'Ustawienia projektu zostały zapisane.');
	}

	public function status(UpdateProjectStatusRequest $request, Project $project): RedirectResponse
	{
		$this->projects->changeStatus($project, $request->status(), Staff::current());

		return back()->with('status', 'Status projektu został zmieniony.');
	}

	public function members(SyncProjectMembersRequest $request, Project $project): RedirectResponse
	{
		try {
			$this->assignments->syncMembers($project, $request->memberIds(), Staff::current());
		} catch (UserManagementException $e) {
			return back()->withErrors(['member_ids' => $e->getMessage()]);
		}

		return back()->with('status', 'Lista pracowników projektu została zapisana.');
	}

	public function destroy(Project $project): RedirectResponse
	{
		$this->authorize('delete', $project);

		$this->projects->delete($project, Staff::current());

		return redirect()
			->route('fmi.projects.index')
			->with('status', 'Projekt „' . $project->name . '” został usunięty.');
	}

	public function destroySelected(DeleteProjectsRequest $request): RedirectResponse
	{
		$count = $this->projects->deleteMany($request->projects(), Staff::current());

		return redirect()
			->route('fmi.projects.index')
			->with('status', 'Usunięte projekty: ' . $count . '.');
	}

	/**
	 * Filtry z adresu, zawsze przez listę dozwolonych wartości.
	 *
	 * @return array{q: string, status: string, mine: string, sort: string, dir: string}
	 */
	private function filters(Request $request): array
	{
		$status = ProjectStatus::tryFrom((string) $request->query('status'));
		$sort = (string) $request->query('sort');
		$dir = strtolower((string) $request->query('dir'));

		return [
			'q' => mb_substr(trim((string) $request->query('q')), 0, 100),
			'status' => $status?->value ?? '',
			'mine' => $request->query('mine') ? '1' : '',
			'sort' => array_key_exists($sort, self::SORTS) ? $sort : 'updated',
			'dir' => in_array($dir, ['asc', 'desc'], true) ? $dir : '',
		];
	}

	/**
	 * @param array{q: string, status: string, mine: string, sort: string, dir: string} $filters
	 */
	private function filteredQuery(array $filters): Builder
	{
		$user = Staff::current();

		return Project::query()
			->visibleTo($user)
			->withModule(ProjectModule::Content)
			->when($filters['q'] !== '', function (Builder $query) use ($filters) {
				// Escapowanie znaków wieloznacznych, żeby wpisany `%` nie zmieniał sensu zapytania.
				$term = '%' . addcslashes($filters['q'], '%_\\') . '%';

				$query->where(fn (Builder $q) => $q->where('name', 'like', $term)->orWhere('client_name', 'like', $term));
			})
			->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
			// Bez jawnego filtra archiwum nie zaśmiecamy listy zakończonymi projektami klientów.
			->when($filters['status'] === '', fn (Builder $query) => $query->where('status', '!=', ProjectStatus::Archived->value))
			->when($filters['mine'] === '1', fn (Builder $query) => $query->where(
				fn (Builder $q) => $q->where('author_id', $user->ID)->orWhere('manager_id', $user->ID)
			));
	}

	/**
	 * Autorzy i opiekunowie listy projektów pobrani jednym zapytaniem do WP (bez N+1).
	 *
	 * @return array<int, \WP_User>
	 */
	private function peopleFor($projects): array
	{
		$ids = collect($projects)
			->flatMap(fn (Project $project) => [$project->author_id, $project->manager_id])
			->filter()
			->unique()
			->values()
			->all();

		if ($ids === []) {
			return [];
		}

		$users = get_users(['include' => $ids, 'fields' => 'all']);

		return collect($users)->keyBy('ID')->all();
	}

	/**
	 * Pracownicy, których można przypisać do projektu (aktywne konta z dostępem do aplikacji).
	 *
	 * @return array<int, string>
	 */
	private function staffOptions(): array
	{
		return collect(get_users(['capability' => Staff::ACCESS_CAPABILITY, 'orderby' => 'display_name']))
			->mapWithKeys(fn ($user) => [$user->ID => Staff::fullName($user) . ' · ' . Staff::roleLabel($user)])
			->all();
	}

	/**
	 * Lista podstron do wyboru rodzica (z wcięciem odzwierciedlającym hierarchię).
	 *
	 * @return array<int, string>
	 */
	private function pageOptions($pages): array
	{
		$options = [];

		$walk = function (array $nodes, int $depth) use (&$walk, &$options): void {
			foreach ($nodes as $node) {
				$options[$node['page']->id] = str_repeat('— ', $depth) . $node['page']->name;

				$walk($node['children'], $depth + 1);
			}
		};

		$walk(Page::tree(collect($pages)->reject(fn (Page $page) => $page->isArchived())), 0);

		return $options;
	}
}
