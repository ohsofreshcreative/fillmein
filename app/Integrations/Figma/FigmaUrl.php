<?php

namespace App\Integrations\Figma;

/**
 * Parsowanie linków do plików i frame'ów Figmy (bez wywołań API).
 *
 * Obsługiwane formaty:
 * - https://www.figma.com/design/{fileKey}/{nazwa}?node-id=1-2
 * - https://www.figma.com/file/{fileKey}/{nazwa}
 * - https://www.figma.com/design/{fileKey}/branch/{branchKey}/{nazwa} → branchKey
 *
 * Prototyp (Design Review): https://www.figma.com/proto/{fileKey}/{nazwa}?node-id=…&starting-point-node-id=…
 * albo link do projektu /design/… — patrz prototype(). Serwer nigdy nie pobiera tych adresów.
 *
 * Link PODSTRONY musi wskazywać konkretny frame, czyli zawierać `node-id`
 * (ustalenie produktowe: osobny frame per podstrona) — patrz isFrame()/nodeId().
 */
final class FigmaUrl
{
	public const PATTERN = '~^https://(?:www\.)?figma\.com/(?:design|file)/([A-Za-z0-9]{10,64})(?:/branch/([A-Za-z0-9]{10,64}))?(?:[/?#].*)?$~';

	/** Prototyp lub projekt: tylko https, tylko figma.com / www.figma.com, bez portu i danych logowania w adresie. */
	public const PROTOTYPE_PATTERN = '~^https://(?:www\.)?figma\.com/(proto|design)/([A-Za-z0-9]{10,64})(?:/branch/([A-Za-z0-9]{10,64}))?(?:/[^?#\s]*)?(?:\?[^#\s]*)?(?:#\S*)?$~';

	public const PROTOTYPE_MAX_LENGTH = 500;

	/**
	 * Zrozumiały powód odrzucenia linku prototypu albo null, gdy link jest poprawny.
	 */
	public static function prototypeError(?string $url): ?string
	{
		$url = trim((string) $url);

		if ($url === '') {
			return 'Wklej link do prototypu Figmy.';
		}

		if (mb_strlen($url) > self::PROTOTYPE_MAX_LENGTH) {
			return 'Link jest za długi (najwyżej ' . self::PROTOTYPE_MAX_LENGTH . ' znaków).';
		}

		$host = strtolower((string) parse_url($url, PHP_URL_HOST));

		if (! str_starts_with($url, 'https://') || ! in_array($host, ['figma.com', 'www.figma.com'], true)) {
			return 'Link musi prowadzić do Figmy przez https: https://www.figma.com/proto/… albo https://www.figma.com/design/….';
		}

		if (preg_match(self::PROTOTYPE_PATTERN, $url, $matches) !== 1) {
			return 'To nie jest link do prototypu ani projektu Figmy. W Figmie użyj „Share prototype” i skopiuj link.';
		}

		$query = (string) parse_url($url, PHP_URL_QUERY);
		parse_str($query, $params);

		foreach (['node-id', 'starting-point-node-id'] as $param) {
			if (isset($params[$param]) && (! is_string($params[$param]) || self::decodeNodeId($params[$param]) === null)) {
				return 'Parametr ' . $param . ' w linku jest nieprawidłowy. Skopiuj link z Figmy jeszcze raz.';
			}
		}

		return null;
	}

	/**
	 * Rozkład poprawnego linku prototypu: rodzaj (`proto`/`design`), klucz pliku (gałęzi, jeśli jest)
	 * i warstwa startowa (`starting-point-node-id`, a bez niego `node-id`).
	 *
	 * @return array{kind: string, file_key: string, node_id: ?string, url: string}|null
	 */
	public static function prototype(?string $url): ?array
	{
		if (self::prototypeError($url) !== null) {
			return null;
		}

		$url = trim((string) $url);
		preg_match(self::PROTOTYPE_PATTERN, $url, $matches);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
		$node = $params['starting-point-node-id'] ?? $params['node-id'] ?? null;

		return [
			'kind' => $matches[1],
			'file_key' => ($matches[3] ?? '') !== '' ? $matches[3] : $matches[2],
			'node_id' => is_string($node) ? self::decodeNodeId($node) : null,
			'url' => $url,
		];
	}

	public static function isValid(?string $url): bool
	{
		return is_string($url) && preg_match(self::PATTERN, trim($url)) === 1;
	}

	public static function fileKey(string $url): ?string
	{
		if (! preg_match(self::PATTERN, trim($url), $matches)) {
			return null;
		}

		return ($matches[2] ?? '') !== '' ? $matches[2] : $matches[1];
	}

	/**
	 * Link do konkretnego frame'u: poprawny adres Figmy z parametrem `node-id`.
	 */
	public static function isFrame(?string $url): bool
	{
		return self::isValid($url) && self::nodeId((string) $url) !== null;
	}

	/**
	 * Identyfikator warstwy w postaci kanonicznej („1:23”). Figma zapisuje go w adresie
	 * jako `1-23` albo `1%3A23`; API przyjmuje wyłącznie postać z dwukropkiem.
	 */
	public static function nodeId(string $url): ?string
	{
		$query = parse_url(trim($url), PHP_URL_QUERY);

		if (! is_string($query) || $query === '') {
			return null;
		}

		parse_str($query, $params);

		$raw = $params['node-id'] ?? $params['node_id'] ?? null;

		if (! is_string($raw) || $raw === '') {
			return null;
		}

		return self::decodeNodeId($raw);
	}

	/**
	 * Postać z adresu (`1-23`, `1%3A23`, `1:23`) → kanoniczna `1:23`; inna wartość → null.
	 */
	public static function decodeNodeId(string $raw): ?string
	{
		$nodeId = str_replace('-', ':', rawurldecode(trim($raw)));

		return self::isNodeId($nodeId) ? $nodeId : null;
	}

	/**
	 * Postać kanoniczna `1:23` → bezpieczna dla ścieżek i adresów `1-23`.
	 */
	public static function encodeNodeId(string $nodeId): string
	{
		if (! self::isNodeId($nodeId)) {
			throw new \InvalidArgumentException('Nieprawidłowy identyfikator warstwy Figmy.');
		}

		return str_replace(':', '-', $nodeId);
	}

	/**
	 * Identyfikator warstwy na płótnie. Celowo bez warstw wewnątrz instancji (`I1:2;3:4`) —
	 * link podstrony wskazuje frame, a nie zagnieżdżony element komponentu.
	 */
	public static function isNodeId(?string $nodeId): bool
	{
		return is_string($nodeId) && preg_match('/^\d{1,10}:\d{1,10}$/', $nodeId) === 1;
	}

	/**
	 * Identyfikator dowolnej warstwy wewnątrz frame'u, także zagnieżdżonej w instancji komponentu
	 * (`I12:34;56:78`). Limit 255 znaków = szerokość kolumn `node_id` / `figma_node_id`.
	 */
	public static function isLayerId(?string $nodeId): bool
	{
		return is_string($nodeId)
			&& strlen($nodeId) <= 255
			&& preg_match('/^(?:\d{1,10}:\d{1,10}|I\d{1,10}:\d{1,10}(?:;\d{1,10}:\d{1,10})+)$/', $nodeId) === 1;
	}

	/**
	 * Klucz pliku i identyfikator frame'u albo null, gdy link nie wskazuje konkretnego frame'u.
	 *
	 * @return array{file_key: string, node_id: string}|null
	 */
	public static function frame(?string $url): ?array
	{
		if (! self::isValid($url)) {
			return null;
		}

		$fileKey = self::fileKey((string) $url);
		$nodeId = self::nodeId((string) $url);

		return $fileKey && $nodeId ? ['file_key' => $fileKey, 'node_id' => $nodeId] : null;
	}
}
