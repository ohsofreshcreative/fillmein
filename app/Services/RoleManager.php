<?php

namespace App\Services;

/**
 * Role i capabilities aplikacji oparte na natywnym systemie ról WordPressa.
 *
 * Autoryzacja w kodzie sprawdza wyłącznie capabilities (`fmi_*`), nigdy nazwy ról.
 * Role to tylko zestawy capabilities. Pojedynczemu użytkownikowi można nadać dodatkowe
 * capabilities (np. Pracownikowi `fmi_export`) — patrz EXTRA_CAPABILITIES.
 *
 * Administrator WordPressa (`manage_options`) dostaje wszystkie capabilities aplikacji
 * automatycznie (filtr w App\Services\AccountStatus) — niezależnie od synchronizacji ról.
 *
 * Synchronizacja jest addytywna: dodaje brakujące role i capabilities, niczego nie usuwa.
 * Uruchamia się automatycznie po zmianie `fillmein.roles_version` lub przez `wp acorn fmi:roles`.
 */
class RoleManager
{
	public const WP_ADMINISTRATOR = 'administrator';

	public const ADMINISTRATOR = 'fmi_administrator';

	public const PROJECT_MANAGER = 'fmi_project_manager';

	public const EMPLOYEE = 'fmi_employee';

	/**
	 * Role nadawane z poziomu aplikacji (kolejność = kolejność w formularzach).
	 */
	public const APP_ROLES = [
		self::ADMINISTRATOR => 'Administrator',
		self::PROJECT_MANAGER => 'Project Manager',
		self::EMPLOYEE => 'Pracownik',
	];

	/**
	 * Role, których użytkownicy są kontami Fill Me In (lista użytkowników, ochrona przed edycją obcych kont).
	 */
	public const STAFF_ROLES = [
		self::WP_ADMINISTRATOR,
		self::ADMINISTRATOR,
		self::PROJECT_MANAGER,
		self::EMPLOYEE,
	];

	/**
	 * Wszystkie capabilities aplikacji z opisem (dokumentacja + panel użytkowników).
	 */
	public const ALL_CAPABILITIES = [
		'fmi_access' => 'Dostęp do aplikacji',
		'fmi_create_projects' => 'Tworzenie projektów',
		'fmi_manage_all_projects' => 'Dostęp do wszystkich projektów',
		'fmi_delete_projects' => 'Usuwanie projektów (własnych lub — z dostępem do wszystkich — dowolnych)',
		'fmi_manage_members' => 'Przypisywanie pracowników do projektów',
		'fmi_configure_fields' => 'Konfiguracja sekcji i pól',
		'fmi_edit_content' => 'Przeglądanie i edycja treści',
		'fmi_manage_reviews' => 'Zarządzanie rewizjami Design Review',
		'fmi_manage_client_access' => 'Generowanie linków klientów',
		'fmi_export' => 'Eksport treści',
		'fmi_sync' => 'Synchronizacja z WordPressem',
		'fmi_view_activity' => 'Historia aktywności przypisanych projektów',
		'fmi_view_all_activity' => 'Historia aktywności całej aplikacji',
		'fmi_manage_users' => 'Zarządzanie użytkownikami i rolami',
		'fmi_manage_integrations' => 'Zarządzanie integracjami',
		'fmi_manage_settings' => 'Ustawienia aplikacji',
	];

	public const CAPABILITIES = [
		self::ADMINISTRATOR => [
			'read',
			'fmi_access',
			'fmi_create_projects',
			'fmi_manage_all_projects',
			'fmi_delete_projects',
			'fmi_manage_members',
			'fmi_configure_fields',
			'fmi_edit_content',
			'fmi_manage_reviews',
			'fmi_manage_client_access',
			'fmi_export',
			'fmi_sync',
			'fmi_view_activity',
			'fmi_view_all_activity',
			'fmi_manage_users',
			'fmi_manage_integrations',
			'fmi_manage_settings',
		],
		self::PROJECT_MANAGER => [
			'read',
			'fmi_access',
			'fmi_create_projects',
			'fmi_delete_projects',
			'fmi_manage_members',
			'fmi_configure_fields',
			'fmi_edit_content',
			'fmi_manage_reviews',
			'fmi_manage_client_access',
			'fmi_export',
			'fmi_view_activity',
		],
		self::EMPLOYEE => [
			'read',
			'fmi_access',
			'fmi_configure_fields',
			'fmi_edit_content',
			'fmi_view_activity',
		],
	];

	/**
	 * Capabilities, które Administrator może nadać pojedynczemu użytkownikowi ponad jego rolę.
	 */
	public const EXTRA_CAPABILITIES = [
		'fmi_export' => 'Może eksportować treści',
		'fmi_sync' => 'Może synchronizować treści z WordPressem',
	];

	private const OPTION = 'fmi_roles_version';

	public function syncIfOutdated(): void
	{
		if ((int) get_option(self::OPTION) !== $this->version()) {
			$this->sync();
		}
	}

	public function sync(): void
	{
		$labels = self::APP_ROLES;

		foreach (self::CAPABILITIES as $roleName => $capabilities) {
			$role = get_role($roleName) ?? add_role($roleName, $labels[$roleName], []);

			if (! $role) {
				continue;
			}

			foreach ($capabilities as $capability) {
				if (! $role->has_cap($capability)) {
					$role->add_cap($capability);
				}
			}
		}

		// Administrator WordPressa ma pełne uprawnienia aplikacji także przez filtr — tu dla spójności panelu WP.
		if ($wpAdmin = get_role(self::WP_ADMINISTRATOR)) {
			foreach (array_keys(self::ALL_CAPABILITIES) as $capability) {
				if (! $wpAdmin->has_cap($capability)) {
					$wpAdmin->add_cap($capability);
				}
			}
		}

		update_option(self::OPTION, $this->version(), true);
	}

	/**
	 * Rola aplikacji użytkownika (pierwsza pasująca). WP administrator jest zwracany jako `administrator`.
	 */
	public static function roleOf(\WP_User $user): ?string
	{
		foreach (self::STAFF_ROLES as $role) {
			if (in_array($role, (array) $user->roles, true)) {
				return $role;
			}
		}

		return null;
	}

	public static function label(?string $role): string
	{
		return match ($role) {
			self::WP_ADMINISTRATOR => 'Administrator (WordPress)',
			null => 'Brak roli',
			default => self::APP_ROLES[$role] ?? $role,
		};
	}

	private function version(): int
	{
		return (int) config('fillmein.roles_version', 1);
	}
}
