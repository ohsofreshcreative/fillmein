<?php

namespace App\Support;

use App\Services\RoleManager;
use WP_User;

/**
 * Pracownicy OhSoFresh = natywni użytkownicy WordPressa.
 *
 * - konto aplikacji: użytkownik z jedną z ról RoleManager::STAFF_ROLES (także nieaktywny),
 * - dostęp do aplikacji: capability `fmi_access` (konto dezaktywowane jej nie ma — AccountGuard).
 */
class Staff
{
	public const ACCESS_CAPABILITY = 'fmi_access';

	/**
	 * Zalogowany pracownik z dostępem do aplikacji albo null.
	 */
	public static function current(): ?WP_User
	{
		if (! is_user_logged_in()) {
			return null;
		}

		$user = wp_get_current_user();

		return $user->exists() && $user->has_cap(self::ACCESS_CAPABILITY) ? $user : null;
	}

	/**
	 * Aktywny pracownik o danym ID (z dostępem do aplikacji) albo null.
	 */
	public static function find(int $userId): ?WP_User
	{
		$user = get_userdata($userId);

		return $user instanceof WP_User && $user->has_cap(self::ACCESS_CAPABILITY) ? $user : null;
	}

	public static function isStaff(int $userId): bool
	{
		return self::find($userId) !== null;
	}

	/**
	 * Czy użytkownik jest kontem Fill Me In (niezależnie od statusu). Chroni przed edycją
	 * z panelu aplikacji kont WordPressa niezwiązanych z aplikacją.
	 */
	public static function isAppUser(WP_User $user): bool
	{
		return RoleManager::roleOf($user) !== null;
	}

	public static function roleLabel(WP_User $user): string
	{
		return RoleManager::label(RoleManager::roleOf($user));
	}

	public static function fullName(WP_User $user): string
	{
		$name = trim($user->first_name . ' ' . $user->last_name);

		return $name !== '' ? $name : $user->display_name;
	}
}
