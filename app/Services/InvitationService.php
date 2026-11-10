<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Support\Actor;
use WP_Error;
use WP_User;

/**
 * Zaproszenia pracowników oparte na natywnym mechanizmie resetu hasła WordPressa:
 * klucz generuje `get_password_reset_key()` (WP przechowuje tylko jego hash w `user_activation_key`),
 * weryfikuje `check_password_reset_key()`, hasło ustawia `reset_password()`.
 * Aplikacja nie przechowuje własnych tokenów ani haseł.
 */
class InvitationService
{
	public function __construct(private ActivityLogger $activity)
	{
	}

	public function send(WP_User $user, WP_User $inviter): InvitationResult
	{
		$key = get_password_reset_key($user);

		if ($key instanceof WP_Error) {
			return $this->failed($user, $inviter, 'Nie udało się wygenerować linku do ustawienia hasła.');
		}

		$url = route('fmi.password.set', ['login' => $user->user_login, 'key' => $key]);

		$body = view('fillmein.emails.invitation', [
			'user' => $user,
			'inviter' => $inviter,
			'url' => $url,
			'expirationDays' => (int) config('fillmein.invitations.expiration_days', 7),
			'siteUrl' => home_url('/app'),
		])->render();

		$mailError = null;
		$captureError = function (WP_Error $error) use (&$mailError) {
			$mailError = $error->get_error_message();
		};

		add_action('wp_mail_failed', $captureError);

		$sent = wp_mail(
			$user->user_email,
			'Zaproszenie do Fill Me In — OhSoFresh Creative',
			$body,
			['Content-Type: text/html; charset=UTF-8'],
		);

		remove_action('wp_mail_failed', $captureError);
		unset($key, $url, $body);

		if (! $sent) {
			return $this->failed($user, $inviter, $mailError ?: 'Serwer nie wysłał wiadomości e-mail.');
		}

		update_user_meta($user->ID, AccountStatus::META_INVITATION_SENT_AT, current_time('mysql', true));
		$this->activity->log('user.invitation_sent', Actor::user($inviter), null, $user, ['email' => $user->user_email]);

		return new InvitationResult(true);
	}

	/**
	 * Ważność klucza: zaproszenia (konto bez ustawionego hasła) dostają dłuższy termin niż zwykły reset.
	 */
	public function expirationFor(WP_User $user): int
	{
		return AccountStatus::of($user) === AccountStatus::Invited
			? (int) config('fillmein.invitations.expiration_days', 7) * DAY_IN_SECONDS
			: DAY_IN_SECONDS;
	}

	private function failed(WP_User $user, WP_User $inviter, string $reason): InvitationResult
	{
		// Treść błędu wysyłki może zawierać szczegóły serwera — w dzienniku zapisujemy tylko fakt niepowodzenia.
		$this->activity->log('user.invitation_failed', Actor::user($inviter), null, $user, ['email' => $user->user_email]);

		return new InvitationResult(false, $reason);
	}
}
