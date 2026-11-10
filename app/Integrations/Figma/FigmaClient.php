<?php

namespace App\Integrations\Figma;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Klient Figma REST API (tylko odczyt).
 *
 * - Dostęp: stała FMI_FIGMA_TOKEN w wp-config.php lub zmienna środowiskowa (pierwszeństwo), a bez nich wspólne
 *   połączenie OAuth (FigmaConnection). Token nigdy w logach, konfiguracji ani komunikatach błędów.
 * - Brak SSRF: adres API jest stały, a ścieżki powstają wyłącznie ze zwalidowanego klucza pliku
 *   i ID warstw. Obrazy pobieramy tylko z hostów z `fillmein.figma.image_hosts`, bez tokenu
 *   i bez przekierowań.
 * - Ponowienia z limitem (`max_attempts`) tylko dla 429, 5xx i błędów połączenia;
 *   401/403/404 kończą się od razu.
 */
class FigmaClient
{
	public const TOKEN_NAME = 'FMI_FIGMA_TOKEN';

	private const FILE_KEY = '/^[A-Za-z0-9]{10,64}$/';

	/** @var Closure(float): void */
	private Closure $sleep;

	/** Termin (microtime) całego żądania HTTP aplikacji — po nim nie wysyłamy ani nie ponawiamy zapytań. */
	private ?float $deadline = null;

	public function __construct(
		private HttpFactory $http,
		?Closure $sleep = null,
		private ?string $token = null,
		private ?FigmaConnection $connection = null,
	) {
		$this->sleep = $sleep ?? static function (float $seconds): void {
			usleep((int) round($seconds * 1_000_000));
		};
		$this->token ??= self::configuredToken();

		// Kontener zostawia opcjonalny parametr pusty, więc połączenie trzeba pobrać jawnie.
		if ($this->token === null) {
			$this->connection ??= app(FigmaConnection::class);
		}
	}

	public static function configuredToken(): ?string
	{
		$token = defined(self::TOKEN_NAME) ? constant(self::TOKEN_NAME) : getenv(self::TOKEN_NAME);

		return is_string($token) && trim($token) !== '' ? trim($token) : null;
	}

	public function isConfigured(): bool
	{
		return $this->token !== null || (bool) $this->connection?->isConnected();
	}

	/**
	 * Wskazane warstwy pliku wraz z poddrzewem do `$depth` poziomów (GET /v1/files/:key/nodes).
	 * Endpoint zwraca wyłącznie żądane węzły — nigdy cały dokument.
	 *
	 * @param string[] $nodeIds
	 */
	public function fileNodes(string $fileKey, array $nodeIds, ?int $depth = null): array
	{
		$query = ['ids' => implode(',', $this->nodeIds($nodeIds))];

		if ($depth !== null) {
			$query['depth'] = max(1, $depth);
		}

		return $this->get('files/' . $this->fileKey($fileKey) . '/nodes', $query);
	}

	/**
	 * Tymczasowe adresy wyrenderowanych obrazów warstw (GET /v1/images/:key).
	 * `use_absolute_bounds=true`: obraz ma dokładnie granice warstwy (absoluteBoundingBox). Bez tego Figma renderuje
	 * też elementy wystające poza frame bez przycinania (np. slider), więc obraz jest szerszy niż frame.
	 *
	 * @param string[] $nodeIds
	 * @return array<string, ?string> node_id → adres (null, gdy Figma nie wyrenderowała warstwy)
	 */
	public function images(string $fileKey, array $nodeIds, string $format = 'png', float $scale = 1): array
	{
		$format = in_array($format, ['png', 'jpg'], true) ? $format : 'png';
		$scale = min(4, max(0.01, $scale));

		$data = $this->get('images/' . $this->fileKey($fileKey), [
			'ids' => implode(',', $this->nodeIds($nodeIds)),
			'format' => $format,
			'scale' => $scale,
			'use_absolute_bounds' => 'true',
		]);

		if (! empty($data['err'])) {
			throw new FigmaException(FigmaException::INVALID_RESPONSE, 'Figma nie wyrenderowała podglądu.');
		}

		if (! isset($data['images']) || ! is_array($data['images'])) {
			throw new FigmaException(FigmaException::INVALID_RESPONSE, 'Odpowiedź Figma API nie zawiera podglądów.');
		}

		$images = [];

		foreach ($data['images'] as $nodeId => $url) {
			$images[(string) $nodeId] = is_string($url) && $url !== '' ? $url : null;
		}

		return $images;
	}

	/**
	 * Pobranie wyrenderowanego obrazu z dozwolonego hosta. Bez tokenu (adres jest podpisany).
	 */
	public function download(string $url, ?int $maxBytes = null): string
	{
		$this->assertImageUrl($url);

		$maxBytes ??= (int) config('fillmein.figma.max_image_kb', 25 * 1024) * 1024;

		$response = $this->send(fn () => $this->http
			->withoutRedirecting()
			->timeout($this->attemptTimeout())
			->connectTimeout((float) config('fillmein.figma.connect_timeout', 5))
			->withOptions(['on_headers' => $this->sizeGuard($maxBytes)])
			->get($url));

		$body = $response->body();

		if (strlen($body) > $maxBytes) {
			throw new FigmaException(FigmaException::INVALID_RESPONSE, 'Podgląd z Figmy przekracza dopuszczalny rozmiar.');
		}

		return $body;
	}

	public function assertImageUrl(string $url): void
	{
		$parts = parse_url($url);
		$host = strtolower((string) ($parts['host'] ?? ''));

		$allowed = ($parts['scheme'] ?? '') === 'https'
			&& ! isset($parts['user'])
			&& ! isset($parts['pass'])
			&& ! isset($parts['port'])
			&& in_array($host, array_map('strtolower', (array) config('fillmein.figma.image_hosts', [])), true);

		if (! $allowed) {
			throw new FigmaException(FigmaException::DISALLOWED_URL, 'Figma zwróciła adres podglądu spoza dozwolonych hostów.');
		}
	}

	private function get(string $path, array $query): array
	{
		if (! $this->isConfigured()) {
			throw new FigmaException(FigmaException::NOT_CONFIGURED, 'Brak tokenu Figma API. Połącz Figmę w ustawieniach aplikacji. Dodaj stałą FMI_FIGMA_TOKEN w wp-config.php tylko wtedy, gdy nie używasz połączenia.');
		}

		$maxBytes = (int) config('fillmein.figma.max_response_kb', 30 * 1024) * 1024;
		$headers = $this->authorization();

		$response = $this->send(fn () => $this->http
			->baseUrl((string) config('fillmein.figma.api_url', 'https://api.figma.com/v1/'))
			->withHeaders($headers)
			->acceptJson()
			->withoutRedirecting()
			->timeout($this->attemptTimeout())
			->connectTimeout((float) config('fillmein.figma.connect_timeout', 5))
			->withOptions(['on_headers' => $this->sizeGuard($maxBytes)])
			->get($path, $query));

		if (strlen($response->body()) > $maxBytes) {
			throw new FigmaException(FigmaException::INVALID_RESPONSE, 'Odpowiedź Figma API przekracza dopuszczalny rozmiar.');
		}

		$data = json_decode($response->body(), true);

		if (! is_array($data)) {
			throw new FigmaException(FigmaException::INVALID_RESPONSE, 'Figma API zwróciło nieprawidłowe dane.');
		}

		return $data;
	}

	private function authorization(): array
	{
		if ($this->token !== null) {
			return ['X-Figma-Token' => $this->token];
		}

		$token = $this->connection?->accessToken();

		if ($token === null) {
			throw new FigmaException(FigmaException::NOT_CONFIGURED, 'Połączenie z Figmą wygasło. Połącz ponownie w ustawieniach.');
		}

		return ['Authorization' => 'Bearer ' . $token];
	}

	/**
	 * Wykonanie żądania z ograniczoną liczbą prób.
	 *
	 * @param Closure(): Response $request
	 */
	private function send(Closure $request): Response
	{
		$maxAttempts = max(1, (int) config('fillmein.figma.max_attempts', 3));
		$maxRetryAfter = (float) config('fillmein.figma.max_retry_after', 10);
		$attempt = 0;

		while (true) {
			$attempt++;

			try {
				$response = $request();
			} catch (ConnectionException $e) {
				// Przekroczenie rozmiaru z `on_headers` Laravel zgłasza jako błąd połączenia — nie ponawiamy.
				if ($cause = $this->figmaCause($e)) {
					throw $cause;
				}

				if ($attempt >= $maxAttempts) {
					throw new FigmaException(FigmaException::TIMEOUT, 'Brak połączenia z Figmą lub przekroczono czas odpowiedzi.', null, $attempt);
				}

				$this->pause($this->backoff($attempt), FigmaException::TIMEOUT, $attempt);

				continue;
			} catch (FigmaException $e) {
				throw $e;
			} catch (\Throwable $e) {
				if ($cause = $this->figmaCause($e)) {
					throw $cause;
				}

				throw new FigmaException(FigmaException::INVALID_RESPONSE, 'Nie udało się odczytać odpowiedzi Figmy.', null, $attempt);
			}

			$status = $response->status();

			if ($status >= 200 && $status < 300) {
				return $response;
			}

			$retryable = $status === 429 || $status >= 500;

			if (! $retryable || $attempt >= $maxAttempts) {
				throw FigmaException::fromStatus($status, $attempt, $status === 403 ? substr($response->body(), 0, 1000) : '');
			}

			$wait = $this->backoff($attempt);

			if ($status === 429) {
				$retryAfter = $response->header('Retry-After');
				$wait = is_numeric($retryAfter) ? (float) $retryAfter : $wait;

				if ($wait > $maxRetryAfter) {
					throw FigmaException::fromStatus($status, $attempt);
				}
			}

			$this->pause(max(0.0, $wait), $status === 429 ? FigmaException::RATE_LIMITED : FigmaException::SERVER, $attempt, $status);
		}
	}

	private function pause(float $seconds, string $kind, int $attempt, ?int $status = null): void
	{
		if ($this->deadline !== null && microtime(true) + $seconds + 2.0 > $this->deadline) {
			throw new FigmaException($kind, $kind === FigmaException::RATE_LIMITED
				? 'Figma chwilowo ogranicza liczbę zapytań — spróbuję ponownie za chwilę.'
				: 'Figma odpowiada zbyt wolno — spróbuję ponownie za chwilę.', $status, $attempt);
		}

		($this->sleep)($seconds);
	}

	/**
	 * Hosting przerywa długie żądania błędem 500 (próby × timeout + przerwy to nawet ~70 s). Z terminem
	 * zapytania kończą się kontrolowanym FigmaException::TIMEOUT, który kolejka ponawia w kolejnym żądaniu.
	 */
	public function setDeadline(?float $seconds): void
	{
		$this->deadline = $seconds === null ? null : microtime(true) + max(1.0, $seconds);
	}

	private function attemptTimeout(): float
	{
		$timeout = (float) config('fillmein.figma.timeout', 20);

		if ($this->deadline === null) {
			return $timeout;
		}

		$left = $this->deadline - microtime(true);

		if ($left < 2.0) {
			throw new FigmaException(FigmaException::TIMEOUT, 'Figma odpowiada zbyt wolno — spróbuję ponownie za chwilę.');
		}

		return min($timeout, $left);
	}

	private function figmaCause(\Throwable $e): ?FigmaException
	{
		for ($current = $e; $current; $current = $current->getPrevious()) {
			if ($current instanceof FigmaException) {
				return $current;
			}
		}

		return null;
	}

	private function backoff(int $attempt): float
	{
		return min(8.0, 0.5 * (2 ** ($attempt - 1)));
	}

	/**
	 * Przerwanie pobierania, gdy serwer zapowiada odpowiedź większą niż limit.
	 */
	private function sizeGuard(int $maxBytes): Closure
	{
		return static function (ResponseInterface $response) use ($maxBytes): void {
			$length = $response->getHeaderLine('Content-Length');

			if (is_numeric($length) && (int) $length > $maxBytes) {
				throw new FigmaException(FigmaException::INVALID_RESPONSE, 'Odpowiedź Figmy przekracza dopuszczalny rozmiar.');
			}
		};
	}

	private function fileKey(string $fileKey): string
	{
		if (preg_match(self::FILE_KEY, $fileKey) !== 1) {
			throw new FigmaException(FigmaException::INVALID_LINK, 'Nieprawidłowy klucz pliku Figmy.');
		}

		return $fileKey;
	}

	/**
	 * @param string[] $nodeIds
	 * @return string[]
	 */
	private function nodeIds(array $nodeIds): array
	{
		$nodeIds = array_values(array_unique(array_map('strval', $nodeIds)));

		if ($nodeIds === []) {
			throw new FigmaException(FigmaException::INVALID_NODE, 'Nie wskazano warstwy Figmy.');
		}

		foreach ($nodeIds as $nodeId) {
			if (! FigmaUrl::isNodeId($nodeId)) {
				throw new FigmaException(FigmaException::INVALID_NODE, 'Nieprawidłowy identyfikator warstwy Figmy.');
			}
		}

		return $nodeIds;
	}
}
