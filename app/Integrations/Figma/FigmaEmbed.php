<?php

namespace App\Integrations\Figma;

/**
 * Adres osadzenia prototypu (Embed Kit 2.0) ze zwalidowanego linku Figmy.
 *
 * Udokumentowane ścieżki: embed.figma.com/proto/{key} (prototyp, interaktywny) i embed.figma.com/design/{key}
 * (podgląd pliku). Parametry: `embed-host` (wymagany), `node-id`, `starting-point-node-id`, `client-id`
 * (tylko dla Embed API). Klucz i warstwy pochodzą z FigmaUrl::prototype(), więc adres nie zawiera danych z żądania.
 */
final class FigmaEmbed
{
	/**
	 * @return array{src: string, kind: string, interactive: bool, events: bool, open_url: string}|null
	 */
	public static function forLink(?string $url, ?string $clientId = null): ?array
	{
		$link = FigmaUrl::prototype($url);

		if ($link === null) {
			return null;
		}

		parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $params);
		$query = ['embed-host' => (string) config('fillmein.figma.embed.host', 'fillmein')];

		foreach (['node-id', 'starting-point-node-id'] as $param) {
			if (is_string($params[$param] ?? null) && ($node = FigmaUrl::decodeNodeId($params[$param])) !== null) {
				$query[$param] = $param === 'node-id' ? str_replace(':', '-', $node) : $node;
			}
		}

		$clientId = trim((string) $clientId);
		$events = $link['kind'] === 'proto' && config('fillmein.figma.embed.embed_api', true) && preg_match('/^[A-Za-z0-9]{8,64}$/', $clientId) === 1;

		if ($events) {
			$query['client-id'] = $clientId;
		}

		return [
			'src' => rtrim((string) config('fillmein.figma.embed.url', 'https://embed.figma.com/'), '/') . '/' . $link['kind'] . '/' . $link['file_key'] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
			'kind' => $link['kind'],
			'interactive' => $link['kind'] === 'proto',
			'events' => $events,
			'open_url' => $link['url'],
		];
	}
}
