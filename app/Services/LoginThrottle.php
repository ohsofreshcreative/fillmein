<?php

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;
use WP_Error;
use WP_User;

/**
 * Ograniczanie prób logowania — wspólne dla /app/login i wp-login.php (hooki WordPressa).
 *
 * Dwa limity: na konto (login + IP) i na adres IP. Liczone są wyłącznie nieudane próby
 * (`wp_login_failed`); udane logowanie zeruje licznik konta. Liczniki trzyma cache Laravela.
 */
class LoginThrottle
{
	public function register(): void
	{
		add_filter('authenticate', [$this, 'blockWhenLocked'], 99, 2);
		add_action('wp_login_failed', [$this, 'recordFailure']);
		add_action('wp_login', [$this, 'clear'], 10, 1);
	}

	public function blockWhenLocked(mixed $user, ?string $login): mixed
	{
		if ($login !== null && $login !== '' && $this->locked($login)) {
			return new WP_Error('fmi_throttled', $this->message($login));
		}

		return $user;
	}

	public function recordFailure(string $login): void
	{
		RateLimiter::hit($this->accountKey($login), (int) config('fillmein.auth.decay_seconds', 300));
		RateLimiter::hit($this->ipKey(), (int) config('fillmein.auth.ip_decay_seconds', 900));
	}

	public function clear(string $login): void
	{
		RateLimiter::clear($this->accountKey($login));
	}

	public function locked(string $login): bool
	{
		return RateLimiter::tooManyAttempts($this->accountKey($login), (int) config('fillmein.auth.max_attempts', 5))
			|| RateLimiter::tooManyAttempts($this->ipKey(), (int) config('fillmein.auth.max_attempts_per_ip', 30));
	}

	public function message(string $login): string
	{
		$seconds = max(
			RateLimiter::availableIn($this->accountKey($login)),
			RateLimiter::tooManyAttempts($this->ipKey(), (int) config('fillmein.auth.max_attempts_per_ip', 30)) ? RateLimiter::availableIn($this->ipKey()) : 0,
		);

		return sprintf('Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za %d min.', max(1, (int) ceil($seconds / 60)));
	}

	private function accountKey(string $login): string
	{
		return 'fmi-login:' . sha1(mb_strtolower(trim($login)) . '|' . $this->ip());
	}

	private function ipKey(): string
	{
		return 'fmi-login-ip:' . sha1($this->ip());
	}

	private function ip(): string
	{
		return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
	}
}
