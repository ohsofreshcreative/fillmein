<?php

namespace App\Http\Controllers\App;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Project;
use App\Support\ProjectProgress;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;

/**
 * Pulpit (/app). Wszystkie liczby pochodzą z bazy i z zakresu widoczności użytkownika
 * (Project::visibleTo) — na pulpicie nie ma danych przykładowych.
 */
class DashboardController extends Controller
{
	public function __invoke(Gate $gate): View
	{
		$user = Staff::current();

		$counts = Project::query()
			->visibleTo($user)
			->selectRaw('status, COUNT(*) as total')
			->groupBy('status')
			->pluck('total', 'status');

		$visibleIds = Project::query()->visibleTo($user)->pluck('id')->map(fn ($id) => (int) $id);

		$recent = Project::query()
			->visibleTo($user)
			->where('status', '!=', ProjectStatus::Archived->value)
			->latest('updated_at')
			->limit(5)
			->get();

		$mine = Project::query()
			->visibleTo($user)
			->where('status', '!=', ProjectStatus::Archived->value)
			->where(fn (Builder $query) => $query->where('author_id', $user->ID)->orWhere('manager_id', $user->ID))
			->latest('updated_at')
			->limit(5)
			->get();

		return view('fillmein.dashboard', [
			'user' => $user,
			'statuses' => collect(ProjectStatus::cases())
				->mapWithKeys(fn (ProjectStatus $status) => [$status->value => [
					'status' => $status,
					'total' => (int) ($counts[$status->value] ?? 0),
				]]),
			'recent' => $recent,
			'mine' => $mine,
			'progress' => ProjectProgress::forProjects($recent->merge($mine)),
			'totals' => [
				'projects' => $visibleIds->count(),
				'pages' => $visibleIds->isEmpty() ? 0 : Page::query()
					->whereIn('project_id', $visibleIds)
					->whereNull('archived_at')
					->count(),
				'clientLinks' => $visibleIds->isEmpty() ? 0 : DB::table('fmi_client_accesses')
					->whereIn('project_id', $visibleIds)
					->whereNull('revoked_at')
					->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
					->count(),
			],
			'canCreate' => $gate->allows('create', Project::class),
		]);
	}
}
