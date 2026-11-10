<?php

namespace App\Integrations\Figma;

use App\Support\SecretBox;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;

/**
 * Wspólne połączenie aplikacji z Figmą przez OAuth 2 (kod autoryzacji z PKCE).
 *
 * Client ID, Client Secret i tokeny leżą w jednej opcji, w całości zaszyfrowanej (SecretBox), bez autoload.
 * Wartości nie trafiają do logów, widoków ani komunikatów błędów.
 *
 * Figma trzyma jeden token na aplikację i użytkownika, a odświeżenie unieważnia poprzedni, więc równoległe
 * odświeżenia są szeregowane blokadą MySQL.
 */
class FigmaConnection
{
	public const OPTION = 'fmi_figma_connection';

	private const REFRESH_LOCK = 'fmi_figma_refresh';

	public function __construct(private HttpFactory $http)
	{
	}

	public function hasCredentials(): bool
	{
		$data = $this->read();

		return ($data['client_id'] ?? '') !== '' && ($data['client_secret'] ?? '') !== '';
	}

	public function isConnected(): bool
	{
		return ($this->read()['access_token'] ?? '') !== '';
	}

	/**
	 * Dane do widoku ustawień, bez sekretów. `unreadable`: opcja istnieje, ale nie da się jej odszyfrować (zmiana soli).
	 *
	 * @return array{client_id: string, has_secret: bool, connected: bool, connected_at: ?int, connected_by: ?int, expires_at: ?int, unreadable: bool}
	 */
	public function status(): array
	{
		$data = $this->read();

		return [
			'client_id' => (string) ($data['client_id'] ?? ''),
			'has_secret' => ($data['client_secret'] ?? '') !== '',
			'connected' => ($data['access_token'] ?? '') !== '',
			'connected_at' => $data['connected_at'] ?? null,
			'connected_by' => $data['connected_by'] ?? null,
			'expires_at' => $data['expires_at'] ?? null,
			'unreadable' => $data === null && get_option(self::OPTION, '') !== '',
		];
	}

	/**
	 * Pusty sekret zachowuje zapisany. Zmiana Client ID kasuje tokeny, bo należą do poprzedniej aplikacji.
	 */
	public function saveCredentials(string $clientId, #[\SensitiveParameter] ?string $clientSecret): void
	{
		$data = $this->read() ?? [];

		if (($data['client_id'] ?? '') !== $clientId) {
			$data = array_diff_key($data, array_flip(['access_token', 'refresh_token', 'expires_at', 'connected_at', 'connected_by']));
		}

		$data['client_id'] = $clientId;

		if ($clientSecret !== null && $clientSecret !== '') {
			$data['client_secret'] = $clientSecret;
		}

		$this->write($data);
	}

	public function authorizationUrl(string $redirectUri, string $state, string $verifier): string
	{
		return config('fillmein.figma.oauth.authorize_url') . '?' . http_build_query([
			'client_id' => $this->read()['client_id'] ?? '',
			'redirect_uri' => $redirectUri,
			'scope' => config('fillmein.figma.oauth.scope'),
			'state' => $state,
			'response_type' => 'code',
			'code_challenge' => self::challenge($verifier),
			'code_challenge_method' => 'S256',
		], '', '&', PHP_QUERY_RFC3986);
	}

	public static function challenge(string $verifier): string
	{
		return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	}

	public function connect(#[\SensitiveParameter] string $code, string $verifier, string $redirectUri, int $userId): void
	{
		$data = $this->read() ?? [];

		$payload = $this->tokenRequest($data, (string) config('fillmein.figma.oauth.token_url'), [
			'redirect_uri' => $redirectUri,
			'code' => $code,
			'grant_type' => 'authorization_code',
			'code_verifier' => $verifier,
		]);

		if (! is_string($payload['refresh_token'] ?? null) || $payload['refresh_token'] === '') {
			throw new FigmaException(FigmaException::INVALID_RESPONSE, 'Figma nie zwróciła tokenu odświeżania. Spróbuj połączyć ponownie.');
		}

		$this->write([
			'refresh_token' => $payload['refresh_token'],
			'connected_at' => time(),
			'connected_by' => $userId,
			'access_token' => $payload['access_token'],
			'expires_at' => time() + (int) $payload['expires_in'],
		] + $data);
	}

	/**
	 * Aktualny token; odświeżany z wyprzedzeniem, żeby nie wygasł w trakcie pobierania.
	 */
	public function accessToken(): ?string
	{
		$data = $this->read();

		if (($data['access_token'] ?? '') === '') {
			return null;
		}

		if (! $this->expiring($data)) {
			return $data['access_token'];
		}

		DB::scalar('SELECT GET_LOCK(?, 10)', [self::REFRESH_LOCK]);

		try {
			// Inny proces mógł odświeżyć token, gdy czekaliśmy na blokadę.
			wp_cache_delete(self::OPTION, 'options');
			wp_cache_delete('alloptions', 'options');
			$data = $this->read() ?? [];

			return $this->expiring($data) ? $this->refresh($data) : ($data['access_token'] ?? null);
		} finally {
			DB::scalar('SELECT RELEASE_LOCK(?)', [self::REFRESH_LOCK]);
		}
	}

	/**
	 * Client ID i sekret zostają, żeby ponowne połączenie wymagało tylko kliknięcia.
	 */
	public function disconnect(): void
	{
		$data = $this->read();

		if ($data === null) {
			delete_option(self::OPTION);

			return;
		}

		$this->write(array_diff_key($data, array_flip(['access_token', 'refresh_token', 'expires_at', 'connected_at', 'connected_by'])));
	}

	private function expiring(array $data): bool
	{
		return (int) ($data['expires_at'] ?? 0) - time() < (int) config('fillmein.figma.oauth.refresh_margin', 86400);
	}

	private function refresh(array $data): string
	{
		try {
			$payload = $this->tokenRequest($data, (string) config('fillmein.figma.oauth.refresh_url'), [
				'refresh_token' => $data['refresh_token'] ?? '',
			]);
		} catch (FigmaException $e) {
			// Awaria Figmy nie może zablokować pracy, dopóki obecny token jest ważny.
			if ($e->status !== 400 && $e->status !== 401 && $e->status !== 403 && (int) ($data['expires_at'] ?? 0) > time()) {
				return $data['access_token'];
			}

			throw $e->status >= 400 && $e->status < 500
				? new FigmaException(FigmaException::UNAUTHORIZED, 'Połączenie z Figmą wygasło. Połącz ponownie w ustawieniach.', $e->status)
				: $e;
		}

		$data['access_token'] = $payload['access_token'];
		$data['expires_at'] = time() + (int) $payload['expires_in'];

		if (is_string($payload['refresh_token'] ?? null) && $payload['refresh_token'] !== '') {
			$data['refresh_token'] = $payload['refresh_token'];
		}

		$this->write($data);

		return $data['access_token'];
	}

	/**
	 * Treści odpowiedzi nie przekazujemy dalej: mogłaby zawierać dane żądania.
	 */
	private function tokenRequest(array $data, string $url, array $body): array
	{
		if (($data['client_id'] ?? '') === '' || ($data['client_secret'] ?? '') === '') {
			throw new FigmaException(FigmaException::NOT_CONFIGURED, 'Najpierw zapisz Client ID i Client Secret aplikacji Figma.');
		}

		try {
			$response = $this->http
				->asForm()
				->acceptJson()
				->withBasicAuth($data['client_id'], $data['client_secret'])
				->withoutRedirecting()
				->timeout((float) config('fillmein.figma.timeout', 20))
				->connectTimeout((float) config('fillmein.figma.connect_timeout', 5))
				->post($url, $body);
		} catch (ConnectionException) {
			throw new FigmaException(FigmaException::TIMEOUT, 'Brak połączenia z Figmą lub przekroczono czas odpowiedzi.');
		}

		return $this->payload($response);
	}

	private function payload(Response $response): array
	{
		if (! $response->successful()) {
			throw new FigmaException(FigmaException::UNAUTHORIZED, "Figma odrzuciła autoryzację ({$response->status()}). Sprawdź Client ID, Client Secret i adres powrotu.", $response->status());
		}

		$payload = $response->json();

		if (! is_array($payload) || ! is_string($payload['access_token'] ?? null) || $payload['access_token'] === '' || ! is_numeric($payload['expires_in'] ?? null)) {
			throw new FigmaException(FigmaException::INVALID_RESPONSE, 'Figma zwróciła nieprawidłową odpowiedź autoryzacji.');
		}

		return $payload;
	}

	private function read(): ?array
	{
		$sealed = get_option(self::OPTION, '');

		if (! is_string($sealed) || $sealed === '') {
			return null;
		}

		$plain = SecretBox::open($sealed);
		$data = $plain === null ? null : json_decode($plain, true);

		return is_array($data) ? $data : null;
	}

	private function write(#[\SensitiveParameter] array $data): void
	{
		update_option(self::OPTION, SecretBox::seal((string) json_encode($data)), false);
	}
}
