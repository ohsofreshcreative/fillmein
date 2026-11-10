<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exceptions\UserManagementException;
use App\Support\Actor;
use App\Support\Staff;
use WP_Error;
use WP_Session_Tokens;
use WP_User;
use WP_User_Query;

/**
 * Zarządzanie kontami pracowników na natywnych użytkownikach WordPressa.
 *
 * Reguły bezpieczeństwa (egzekwowane tu, niezależnie od interfejsu):
 * - z panelu aplikacji można zarządzać wyłącznie kontami Fill Me In (rola z RoleManager::STAFF_ROLES),
 * - nadawane są tylko role aplikacji (RoleManager::APP_ROLES) — nigdy role WordPressa,
 * - roli Administratora WordPressa nie zmienia się z aplikacji,
 * - nikt nie zmienia z panelu własnej roli ani własnego statusu,
 * - aplikacja zawsze zachowuje co najmniej jednego aktywnego administratora,
 * - konta nie są usuwane — dezaktywacja zachowuje historię i przypisania.
 */
class UserService
{
	public function __construct(
		private ActivityLogger $activity,
		private InvitationService $invitations,
	) {
	}

	/**
	 * Lista kont aplikacji z wyszukiwaniem i filtrami. Zespół agencji to dziesiątki kont,
	 * więc filtrowanie statusu (meta) i paginacja odbywają się w PHP.
	 *
	 * @return array{users: WP_User[], total: int, page: int, lastPage: int}
	 */
	public function search(string $query = '', ?string $role = null, ?AccountStatus $status = null, int $page = 1, int $perPage = 20): array
	{
		$args = [
			'role__in' => $role && in_array($role, RoleManager::STAFF_ROLES, true) ? [$role] : RoleManager::STAFF_ROLES,
			'orderby' => 'display_name',
			'order' => 'ASC',
			'number' => 1000,
			'count_total' => false,
		];

		if (($query = trim($query)) !== '') {
			$args['search'] = '*' . $query . '*';
			$args['search_columns'] = ['user_login', 'user_email', 'display_name'];
		}

		$users = (new WP_User_Query($args))->get_results();

		if ($status) {
			$users = array_values(array_filter($users, fn (WP_User $user) => AccountStatus::of($user) === $status));
		}

		$total = count($users);
		$lastPage = max(1, (int) ceil($total / $perPage));
		$page = min(max(1, $page), $lastPage);

		return [
			'users' => array_slice($users, ($page - 1) * $perPage, $perPage),
			'total' => $total,
			'page' => $page,
			'lastPage' => $lastPage,
		];
	}

	/**
	 * Tworzy konto i wysyła zaproszenie. Konto powstaje także wtedy, gdy wysyłka się nie uda —
	 * wtedy zaproszenie nie jest oznaczone jako wysłane, a Administrator może je ponowić.
	 *
	 * @param array{first_name: string, last_name: string, email: string, role: string, capabilities?: string[]} $data
	 * @return array{user: WP_User, invitation: InvitationResult}
	 */
	public function invite(array $data, WP_User $actor): array
	{
		$this->assertAppRole($data['role']);

		if (email_exists($data['email'])) {
			throw new UserManagementException('Ten adres e-mail jest już używany przez inne konto WordPress.', 'email');
		}

		$userId = wp_insert_user([
			'user_login' => $this->uniqueLogin($data['email']),
			'user_email' => $data['email'],
			'user_pass' => wp_generate_password(32, true, true),
			'first_name' => $data['first_name'],
			'last_name' => $data['last_name'],
			'display_name' => trim($data['first_name'] . ' ' . $data['last_name']),
			'role' => $data['role'],
			'show_admin_bar_front' => 'false',
		]);

		if ($userId instanceof WP_Error) {
			throw new UserManagementException('Nie udało się utworzyć konta: ' . $userId->get_error_message());
		}

		update_user_meta($userId, AccountStatus::META_INVITED_AT, current_time('mysql', true));
		update_user_meta($userId, AccountStatus::META_INVITED_BY, $actor->ID);

		$user = new WP_User($userId);
		$this->applyExtraCapabilities($user, $data['capabilities'] ?? []);

		$this->activity->log('user.created', Actor::user($actor), null, $user, [
			'email' => $user->user_email,
			'role' => $data['role'],
		]);

		return [
			'user' => $user,
			'invitation' => $this->invitations->send($user, $actor),
		];
	}

	public function resendInvitation(WP_User $user, WP_User $actor): InvitationResult
	{
		$this->assertManageable($user);

		if (AccountStatus::of($user) === AccountStatus::Inactive) {
			throw new UserManagementException('Konto jest nieaktywne. Najpierw je aktywuj.');
		}

		return $this->invitations->send($user, $actor);
	}

	/**
	 * Edycja konta przez Administratora: imię, nazwisko, rola, dodatkowe uprawnienia.
	 *
	 * @param array{first_name: string, last_name: string, role?: ?string, capabilities?: string[]} $data
	 */
	public function update(WP_User $user, array $data, WP_User $actor): void
	{
		$this->assertManageable($user);

		$this->updateName($user, $data['first_name'], $data['last_name'], $actor);

		if (! empty($data['role']) && $data['role'] !== RoleManager::roleOf($user)) {
			$this->changeRole($user, $data['role'], $actor);
		}

		if (array_key_exists('capabilities', $data)) {
			$this->setExtraCapabilities($user, $data['capabilities'] ?? [], $actor);
		}
	}

	public function updateName(WP_User $user, string $firstName, string $lastName, WP_User $actor): void
	{
		$changed = array_keys(array_filter([
			'first_name' => $user->first_name !== $firstName,
			'last_name' => $user->last_name !== $lastName,
		]));

		if (! $changed) {
			return;
		}

		$result = wp_update_user([
			'ID' => $user->ID,
			'first_name' => $firstName,
			'last_name' => $lastName,
			'display_name' => trim($firstName . ' ' . $lastName),
		]);

		if ($result instanceof WP_Error) {
			throw new UserManagementException('Nie udało się zapisać danych: ' . $result->get_error_message());
		}

		$this->activity->log('user.updated', Actor::user($actor), null, $user, ['fields' => $changed]);
	}

	public function changeRole(WP_User $user, string $role, WP_User $actor): void
	{
		$this->assertManageable($user);
		$this->assertAppRole($role);
		$this->assertNotSelf($user, $actor, 'Nie możesz zmienić własnej roli.');

		$current = RoleManager::roleOf($user);

		if ($current === RoleManager::WP_ADMINISTRATOR) {
			throw new UserManagementException('Rolę Administratora WordPressa zmienia się w panelu WordPressa.', 'role');
		}

		if ($current === $role) {
			return;
		}

		if ($this->isAdministrator($user) && $role !== RoleManager::ADMINISTRATOR) {
			$this->assertAnotherAdministrator($user);
		}

		foreach (array_keys(RoleManager::APP_ROLES) as $appRole) {
			$user->remove_role($appRole);
		}

		$user->add_role($role);

		$this->activity->log('user.role_changed', Actor::user($actor), null, $user, ['from' => $current, 'to' => $role]);
	}

	/**
	 * Dodatkowe capabilities nadawane pojedynczemu użytkownikowi (RoleManager::EXTRA_CAPABILITIES).
	 *
	 * @param string[] $capabilities
	 */
	public function setExtraCapabilities(WP_User $user, array $capabilities, WP_User $actor): void
	{
		$this->assertManageable($user);

		$before = $this->extraCapabilities($user);
		$this->applyExtraCapabilities($user, $capabilities);
		$after = $this->extraCapabilities(new WP_User($user->ID));

		if ($before !== $after) {
			$this->activity->log('user.capabilities_changed', Actor::user($actor), null, $user, [
				'from' => $before,
				'to' => $after,
			]);
		}
	}

	/**
	 * Dodatkowe capabilities nadane użytkownikowi bezpośrednio (nie przez rolę).
	 *
	 * @return string[]
	 */
	public function extraCapabilities(WP_User $user): array
	{
		return array_values(array_filter(
			array_keys(RoleManager::EXTRA_CAPABILITIES),
			fn (string $capability) => ! empty($user->caps[$capability]),
		));
	}

	public function deactivate(WP_User $user, WP_User $actor): void
	{
		$this->assertManageable($user);
		$this->assertNotSelf($user, $actor, 'Nie możesz dezaktywować własnego konta.');

		if (AccountStatus::isDeactivated($user->ID)) {
			return;
		}

		if ($this->isAdministrator($user)) {
			$this->assertAnotherAdministrator($user);
		}

		update_user_meta($user->ID, AccountStatus::META_DEACTIVATED_AT, current_time('mysql', true));
		update_user_meta($user->ID, AccountStatus::META_DEACTIVATED_BY, $actor->ID);

		// Natychmiastowe wylogowanie ze wszystkich urządzeń.
		WP_Session_Tokens::get_instance($user->ID)->destroy_all();

		$this->activity->log('user.deactivated', Actor::user($actor), null, $user);
	}

	public function reactivate(WP_User $user, WP_User $actor): void
	{
		$this->assertManageable($user);
		$this->assertNotSelf($user, $actor, 'Nie możesz zmienić statusu własnego konta.');

		if (! AccountStatus::isDeactivated($user->ID)) {
			return;
		}

		delete_user_meta($user->ID, AccountStatus::META_DEACTIVATED_AT);
		delete_user_meta($user->ID, AccountStatus::META_DEACTIVATED_BY);

		$this->activity->log('user.reactivated', Actor::user($actor), null, $user);
	}

	/**
	 * Zmiana własnego hasła: wymaga aktualnego hasła. Po zmianie wszystkie inne sesje są
	 * unieważniane, a bieżąca dostaje nowe ciasteczko (stare przestaje być ważne po zmianie hasła).
	 */
	public function changeOwnPassword(WP_User $user, string $currentPassword, string $newPassword): void
	{
		if (! wp_check_password($currentPassword, $user->user_pass, $user->ID)) {
			throw new UserManagementException('Aktualne hasło jest nieprawidłowe.', 'current_password');
		}

		$session = WP_Session_Tokens::get_instance($user->ID)->get(wp_get_session_token());
		$remember = $session && ($session['expiration'] - $session['login']) > 2 * DAY_IN_SECONDS;

		wp_set_password($newPassword, $user->ID);
		WP_Session_Tokens::get_instance($user->ID)->destroy_all();
		wp_set_auth_cookie($user->ID, $remember, is_ssl());

		$this->activity->log('user.password_changed', Actor::user($user), null, $user);
	}

	/**
	 * Aktywni administratorzy aplikacji (rola Administratora aplikacji lub WordPressa, bez dezaktywacji).
	 *
	 * @return int[]
	 */
	public function activeAdministratorIds(): array
	{
		$ids = (new WP_User_Query([
			'role__in' => [RoleManager::ADMINISTRATOR, RoleManager::WP_ADMINISTRATOR],
			'fields' => 'ID',
			'number' => -1,
		]))->get_results();

		return array_values(array_filter(array_map('intval', $ids), fn (int $id) => ! AccountStatus::isDeactivated($id)));
	}

	public function isAdministrator(WP_User $user): bool
	{
		return (bool) array_intersect([RoleManager::ADMINISTRATOR, RoleManager::WP_ADMINISTRATOR], (array) $user->roles);
	}

	public function isManageable(WP_User $user): bool
	{
		return Staff::isAppUser($user);
	}

	private function assertManageable(WP_User $user): void
	{
		if (! $this->isManageable($user)) {
			throw new UserManagementException('To konto nie należy do aplikacji Fill Me In.');
		}
	}

	private function assertAppRole(string $role): void
	{
		if (! array_key_exists($role, RoleManager::APP_ROLES)) {
			throw new UserManagementException('Nieprawidłowa rola.', 'role');
		}
	}

	private function assertNotSelf(WP_User $user, WP_User $actor, string $message): void
	{
		if ($user->ID === $actor->ID) {
			throw new UserManagementException($message);
		}
	}

	private function assertAnotherAdministrator(WP_User $user): void
	{
		$others = array_diff($this->activeAdministratorIds(), [$user->ID]);

		if (! $others) {
			throw new UserManagementException('To ostatni aktywny administrator — aplikacja musi mieć co najmniej jednego.');
		}
	}

	/**
	 * @param string[] $capabilities
	 */
	private function applyExtraCapabilities(WP_User $user, array $capabilities): void
	{
		foreach (array_keys(RoleManager::EXTRA_CAPABILITIES) as $capability) {
			if (in_array($capability, $capabilities, true)) {
				$user->add_cap($capability);
			} elseif (isset($user->caps[$capability])) {
				$user->remove_cap($capability);
			}
		}
	}

	private function uniqueLogin(string $email): string
	{
		$base = sanitize_user(strtok($email, '@') ?: 'pracownik', true) ?: 'pracownik';
		$base = substr($base, 0, 50);
		$login = $base;
		$suffix = 2;

		while (username_exists($login)) {
			$login = $base . $suffix++;
		}

		return $login;
	}
}
