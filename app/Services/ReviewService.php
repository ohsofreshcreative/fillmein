<?php

namespace App\Services;

use App\Enums\ReviewRevisionStatus;
use App\Enums\ReviewThreadStatus;
use App\Integrations\Figma\FigmaUrl;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ReviewRevision;
use App\Models\ReviewThread;
use App\Support\Actor;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use WP_User;

/**
 * Design Review: dane panelu i zapis odnośnika prototypu Figmy.
 *
 * Zapis linku nie łączy się z Figmą (serwer nie pobiera adresów podanych przez użytkownika); wersję pliku
 * utrwala dopiero etap akceptacji. Nowa rewizja powstaje, gdy ostatnia nie jest już szkicem — każda opublikowana
 * rewizja wymaga osobnej decyzji klienta, więc jej odnośnika nie nadpisujemy.
 */
class ReviewService
{
	public function __construct(private ActivityLogger $activity)
	{
	}

	/**
	 * Liczniki dla strony listy, trzema zapytaniami grupowymi niezależnie od liczby projektów.
	 *
	 * @param Collection<int, Project> $projects
	 * @return array<int, array{open_threads: int, revision: ?ReviewRevision, last_activity: ?CarbonInterface}>
	 */
	public function overview(Collection $projects): array
	{
		$ids = $projects->map(fn (Project $project) => $project->getKey())->all();

		if ($ids === []) {
			return [];
		}

		$open = ReviewThread::query()
			->whereIn('project_id', $ids)
			->whereIn('status', ReviewThreadStatus::unresolved())
			->selectRaw('project_id, COUNT(*) AS total')
			->groupBy('project_id')
			->pluck('total', 'project_id');

		$latest = ReviewRevision::query()
			->whereIn('id', ReviewRevision::query()->whereIn('project_id', $ids)->selectRaw('MAX(id)')->groupBy('project_id'))
			->get()
			->keyBy('project_id');

		$activity = Activity::query()
			->whereIn('project_id', $ids)
			->selectRaw('project_id, MAX(created_at) AS last_at')
			->groupBy('project_id')
			->pluck('last_at', 'project_id');

		$result = [];

		foreach ($projects as $project) {
			$logged = isset($activity[$project->id]) ? \Illuminate\Support\Carbon::parse($activity[$project->id]) : null;
			$updated = $project->updated_at;

			$result[$project->id] = [
				'open_threads' => (int) ($open[$project->id] ?? 0),
				'revision' => $latest[$project->id] ?? null,
				'last_activity' => $logged && (! $updated || $logged->greaterThan($updated)) ? $logged : $updated,
			];
		}

		return $result;
	}

	/**
	 * Rewizja, do której trafiają nowe podstrony: ostatnia (o ile nie jest zastąpiona), a gdy projekt nie ma
	 * jeszcze żadnej — nowy szkic bez linku prototypu (link jest opcjonalny, służy tylko do „Otwórz w Figmie”).
	 */
	public function revisionForScreens(Project $project, WP_User $by): ReviewRevision
	{
		return DB::transaction(function () use ($project, $by) {
			$latest = ReviewRevision::query()->where('project_id', $project->id)->lockForUpdate()->orderByDesc('number')->first();

			if ($latest && $latest->status !== ReviewRevisionStatus::Superseded) {
				return $latest;
			}

			$number = ($latest?->number ?? 0) + 1;
			$revision = ReviewRevision::create([
				'project_id' => $project->id,
				'number' => $number,
				'title' => 'Rewizja ' . $number,
				'status' => ReviewRevisionStatus::Draft,
				'created_by' => $by->ID,
			]);

			$this->activity->log('review.revision_created', Actor::user($by), $project, $revision, ['revision' => $number]);

			return $revision;
		});
	}

	/**
	 * Zapis odnośnika prototypu: aktualizacja szkicu albo nowa rewizja (szkic) po rewizji opublikowanej.
	 */
	/**
	 * Jawna nowa rewizja z tym samym linkiem prototypu — gdy zawartość pliku w Figmie zmieniła się pod tym samym
	 * adresem po decyzji. Nowa rewizja czeka na decyzję; poprzednia bez decyzji staje się „Zastąpiona”, poprzednia
	 * z decyzją zachowuje swój status (historia).
	 */
	public function createRevision(Project $project, WP_User $by): ReviewRevision
	{
		return DB::transaction(function () use ($project, $by) {
			$latest = ReviewRevision::query()->where('project_id', $project->id)->lockForUpdate()->orderByDesc('number')->first();

			if (! $latest || (! $latest->figma_url && ! $latest->screens()->exists())) {
				throw new InvalidArgumentException('Najpierw dodaj podstrony do recenzji.');
			}

			if ($latest->status->isPending() && ! $latest->decision()->exists()) {
				$latest->update(['status' => ReviewRevisionStatus::Superseded]);
			}

			$number = $latest->number + 1;
			$revision = ReviewRevision::create([
				'project_id' => $project->id,
				'number' => $number,
				'title' => 'Rewizja ' . $number,
				'status' => ReviewRevisionStatus::Draft,
				'figma_url' => $latest->figma_url,
				'figma_file_key' => $latest->figma_file_key,
				'figma_node_id' => $latest->figma_node_id,
				'created_by' => $by->ID,
			]);

			$this->activity->log('review.revision_created', Actor::user($by), $project, $revision, ['revision' => $number, 'explicit' => true]);

			return $revision;
		});
	}

	public function savePrototype(Project $project, string $url, WP_User $by): ReviewRevision
	{
		$link = FigmaUrl::prototype($url);

		if ($link === null) {
			throw new InvalidArgumentException((string) FigmaUrl::prototypeError($url));
		}

		return DB::transaction(function () use ($project, $link, $by) {
			$latest = ReviewRevision::query()->where('project_id', $project->id)->lockForUpdate()->orderByDesc('number')->first();
			$attributes = [
				'figma_url' => $link['url'],
				'figma_file_key' => $link['file_key'],
				'figma_node_id' => $link['node_id'],
				'figma_version' => null,
			];

			if ($latest && $latest->status === ReviewRevisionStatus::Draft) {
				if ($latest->figma_url === $link['url']) {
					return $latest;
				}

				$latest->update($attributes);
				$this->activity->log('review.prototype_saved', Actor::user($by), $project, $latest, ['revision' => $latest->number]);

				return $latest;
			}

			$number = ($latest?->number ?? 0) + 1;
			$revision = ReviewRevision::create($attributes + [
				'project_id' => $project->id,
				'number' => $number,
				'title' => 'Rewizja ' . $number,
				'status' => ReviewRevisionStatus::Draft,
				'created_by' => $by->ID,
			]);

			$this->activity->log('review.revision_created', Actor::user($by), $project, $revision, ['revision' => $number]);

			return $revision;
		});
	}
}
