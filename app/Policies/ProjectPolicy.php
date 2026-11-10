<?php

namespace App\Policies;

use App\Enums\ActorType;
use App\Enums\ReviewThreadStatus;
use App\Models\Project;
use App\Models\ReviewComment;
use App\Models\ReviewThread;
use Illuminate\Auth\Access\Response;
use WP_User;

/**
 * Autoryzacja projektów: capability + przypisanie do projektu. Nigdy nazwy ról.
 *
 * - `fmi_manage_all_projects` (Administrator) — dostęp do wszystkich projektów (`before`).
 * - Pozostali: tylko projekty, do których są przypisani (autor, opiekun, dodatkowy pracownik)
 *   i tylko operacje, na które mają capability.
 * - Brak przypisania → 404 (nie 403): ręczna zmiana ID w adresie nie zdradza istnienia projektu.
 * - Konto dezaktywowane nie ma żadnej capability `fmi_*` (AccountGuard), więc nie przejdzie żadnej reguły.
 */
class ProjectPolicy
{
	public function before(WP_User $user, string $ability): ?bool
	{
		if (! $user->has_cap('fmi_access')) {
			return false;
		}

		return $user->has_cap('fmi_manage_all_projects') ? true : null;
	}

	public function viewAny(WP_User $user): bool
	{
		return true;
	}

	public function create(WP_User $user): bool
	{
		return $user->has_cap('fmi_create_projects');
	}

	public function view(WP_User $user, Project $project): Response
	{
		return $this->assigned($user, $project);
	}

	/**
	 * Ustawienia projektu (nazwa, klient, status, Figma) — opiekun lub autor z prawem tworzenia projektów.
	 */
	public function update(WP_User $user, Project $project): Response
	{
		return $this->owned($user, $project, 'fmi_create_projects');
	}

	public function editContent(WP_User $user, Project $project): Response
	{
		return $this->assignedWith($user, $project, 'fmi_edit_content');
	}

	public function configureFields(WP_User $user, Project $project): Response
	{
		return $this->assignedWith($user, $project, 'fmi_configure_fields');
	}

	/**
	 * Struktura podstron (tworzenie, hierarchia, duplikowanie) to konfiguracja projektu,
	 * więc wymaga tej samej capability co sekcje i pola.
	 */
	public function managePages(WP_User $user, Project $project): Response
	{
		return $this->assignedWith($user, $project, 'fmi_configure_fields');
	}

	public function manageMembers(WP_User $user, Project $project): Response
	{
		return $this->assignedWith($user, $project, 'fmi_manage_members');
	}

	public function manageClientAccess(WP_User $user, Project $project): Response
	{
		return $this->assignedWith($user, $project, 'fmi_manage_client_access');
	}

	public function export(WP_User $user, Project $project): Response
	{
		return $this->assignedWith($user, $project, 'fmi_export');
	}

	public function sync(WP_User $user, Project $project): Response
	{
		return $this->assignedWith($user, $project, 'fmi_sync');
	}

	public function viewActivity(WP_User $user, Project $project): Response
	{
		return $this->assignedWith($user, $project, 'fmi_view_activity');
	}

	/**
	 * Design Review: podgląd rewizji ma każda osoba przypisana do projektu (te same przypisania co w Treściach).
	 * Czy projekt ma moduł, sprawdza middleware `fmi.module:review`, nie Policy.
	 */
	public function viewReview(WP_User $user, Project $project): Response
	{
		return $this->assigned($user, $project);
	}

	/**
	 * Rewizje Design Review. Moduły projektu zmienia `update` (ustawienia projektu).
	 */
	public function manageReviews(WP_User $user, Project $project): Response
	{
		return $this->assignedWith($user, $project, 'fmi_manage_reviews');
	}

	/**
	 * Decyzję o rewizji (akceptacja / poprawki) zapisuje w panelu osoba przypisana z `fmi_manage_reviews` — konto
	 * uwierzytelnione. Klient decyduje przez link recenzji z `can_approve` (middleware `fmi.review-guest:approve`).
	 */
	public function decideReview(WP_User $user, Project $project): Response
	{
		return $this->assignedWith($user, $project, 'fmi_manage_reviews');
	}

	/**
	 * Status uwagi zmienia osoba z `fmi_manage_reviews`. Autor uwagi (bez tej capability) może tylko ponownie
	 * otworzyć swoją rozwiązaną uwagę — np. gdy poprawka nie zadziałała.
	 */
	public function changeReviewStatus(WP_User $user, Project $project, ReviewThread $thread, ReviewThreadStatus $to): Response
	{
		$assigned = $this->assigned($user, $project);

		if ($assigned->denied() || $thread->project_id !== $project->id) {
			return Response::denyAsNotFound();
		}

		if ($user->has_cap('fmi_manage_reviews')) {
			return Response::allow();
		}

		$reopen = $thread->status === ReviewThreadStatus::Resolved && $to !== ReviewThreadStatus::Resolved;
		$author = $thread->created_by_type === ActorType::User && $thread->created_by_user_id === $user->ID;

		return $reopen && $author
			? Response::allow()
			: Response::deny('Status uwagi zmienia opiekun projektu. Autor może ponownie otworzyć swoją rozwiązaną uwagę.');
	}

	/**
	 * Edytować można wyłącznie własne wiadomości (historia zachowuje poprzednią treść). Nikt — także opiekun —
	 * nie zmienia cudzych wypowiedzi.
	 */
	public function editReviewComment(WP_User $user, Project $project, ReviewComment $comment): Response
	{
		$assigned = $this->assigned($user, $project);

		if ($assigned->denied() || $comment->project_id !== $project->id) {
			return Response::denyAsNotFound();
		}

		return $comment->isAuthoredBy($user) ? Response::allow() : Response::deny('Możesz edytować tylko własne wiadomości.');
	}

	public function delete(WP_User $user, Project $project): Response
	{
		return $this->owned($user, $project, 'fmi_delete_projects');
	}

	private function assigned(WP_User $user, Project $project): Response
	{
		return $project->isAssignedTo($user) ? Response::allow() : Response::denyAsNotFound();
	}

	private function assignedWith(WP_User $user, Project $project, string $capability): Response
	{
		$assigned = $this->assigned($user, $project);

		if ($assigned->denied()) {
			return $assigned;
		}

		return $user->has_cap($capability) ? Response::allow() : Response::deny('Nie masz uprawnień do tej operacji.');
	}

	private function owned(WP_User $user, Project $project, string $capability): Response
	{
		$assigned = $this->assigned($user, $project);

		if ($assigned->denied()) {
			return $assigned;
		}

		return $user->has_cap($capability) && ($project->isAuthor($user) || $project->isManagedBy($user))
			? Response::allow()
			: Response::deny('Tę operację może wykonać autor lub opiekun projektu.');
	}
}
