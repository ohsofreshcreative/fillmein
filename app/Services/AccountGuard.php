<?php

namespace App\Services;

use App\Enums\AccountStatus;
use WP_Error;
use WP_User;

/**
 * Integracja statusu kont z mechanizmami WordPressa (rejestrowana w FillMeInServiceProvider).
 *
 * - Administrator WordPressa (`manage_options`) ma automatycznie wszystkie capabilities `fmi_*`.
 * - Konto dezaktywowane traci wszystkie capabilities `fmi_*` — jedno miejsce, które odcina dostęp
 *   w middleware, politykach i Gate'ach, bez sprawdzania statusu w każdym z nich.
 * - Dezaktywowany pracownik bez innych uprawnień w WP nie zaloguje się też przez wp-login.php.
 * - Każde logowanie zapisuje datę ostatniego logowania.
 */
class AccountGuard
{
	public function register(): void
	{
		add_filter('user_has_cap', [$this, 'filterCapabilities'], 20, 4);
		add_filter('wp_authenticate_user', [$this, 'blockDeactivated'], 20, 2);
		add_action('wp_login', [$this, 'recordLogin'], 10, 2);
	}

	/**
	 * @param array<string, bool> $allcaps
	 */
	public function filterCapabilities(array $allcaps, array $caps, array $args, WP_User $user): array
	{
		if (! $user->ID) {
			return $allcaps;
		}

		if (AccountStatus::isDeactivated($user->ID)) {
			foreach (array_keys(RoleManager::ALL_CAPABILITIES) as $capability) {
				$allcaps[$capability] = false;
			}

			return $allcaps;
		}

		if (! empty($allcaps['manage_options'])) {
			foreach (array_keys(RoleManager::ALL_CAPABILITIES) as $capability) {
				$allcaps[$capability] = true;
			}
		}

		return $allcaps;
	}

	public function blockDeactivated(WP_User|WP_Error $user, string $password): WP_User|WP_Error
	{
		if ($user instanceof WP_User && AccountStatus::isDeactivated($user->ID) && ! $user->has_cap('edit_posts')) {
			return new WP_Error('fmi_account_inactive', __('Konto zostało dezaktywowane. Skontaktuj się z administratorem.', 'fillmein'));
		}

		return $user;
	}

	public function recordLogin(string $login, WP_User $user): void
	{
		update_user_meta($user->ID, AccountStatus::META_LAST_LOGIN_AT, current_time('mysql', true));
	}
}
