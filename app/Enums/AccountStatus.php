<?php

namespace App\Enums;

use WP_User;

/**
 * Status konta pracownika (wyliczany z meta użytkownika WP, bez osobnej tabeli).
 *
 * - Inactive — dezaktywowane przez Administratora (`fmi_deactivated_at`): brak dostępu do aplikacji,
 *   konto, historia i przypisania zostają,
 * - Invited  — utworzone z zaproszeniem, hasło jeszcze nieustawione i brak logowania,
 * - Active   — pozostałe.
 */
enum AccountStatus: string
{
	case Active = 'active';
	case Invited = 'invited';
	case Inactive = 'inactive';

	public const META_DEACTIVATED_AT = 'fmi_deactivated_at';
	public const META_DEACTIVATED_BY = 'fmi_deactivated_by';
	public const META_INVITED_AT = 'fmi_invited_at';
	public const META_INVITED_BY = 'fmi_invited_by';
	public const META_INVITATION_SENT_AT = 'fmi_invitation_sent_at';
	public const META_PASSWORD_SET_AT = 'fmi_password_set_at';
	public const META_LAST_LOGIN_AT = 'fmi_last_login_at';

	public static function of(WP_User|int $user): self
	{
		$id = $user instanceof WP_User ? $user->ID : $user;

		if (get_user_meta($id, self::META_DEACTIVATED_AT, true)) {
			return self::Inactive;
		}

		$invited = get_user_meta($id, self::META_INVITED_AT, true);
		$started = get_user_meta($id, self::META_PASSWORD_SET_AT, true) || get_user_meta($id, self::META_LAST_LOGIN_AT, true);

		return $invited && ! $started ? self::Invited : self::Active;
	}

	public static function isDeactivated(int $userId): bool
	{
		return (bool) get_user_meta($userId, self::META_DEACTIVATED_AT, true);
	}

	public function label(): string
	{
		return match ($this) {
			self::Active => 'Aktywne',
			self::Invited => 'Zaproszone',
			self::Inactive => 'Nieaktywne',
		};
	}

	public function badge(): string
	{
		return match ($this) {
			self::Active => 'success',
			self::Invited => 'warning',
			self::Inactive => 'muted',
		};
	}
}
