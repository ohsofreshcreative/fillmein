<?php

namespace App\Integrations\Figma;

use RuntimeException;

/**
 * Błąd integracji z Figmą. Komunikat jest przeznaczony dla pracownika (po polsku) i nigdy
 * nie zawiera tokenu, nagłówków ani podpisanych adresów obrazów.
 */
final class FigmaException extends RuntimeException
{
	public const NOT_CONFIGURED = 'not_configured';
	public const INVALID_LINK = 'invalid_link';
	public const INVALID_NODE = 'invalid_node';
	public const UNAUTHORIZED = 'unauthorized';
	public const FORBIDDEN = 'forbidden';
	public const NOT_FOUND = 'not_found';
	public const RATE_LIMITED = 'rate_limited';
	public const TIMEOUT = 'timeout';
	public const SERVER = 'server';
	public const BAD_REQUEST = 'bad_request';
	public const INVALID_RESPONSE = 'invalid_response';
	public const DISALLOWED_URL = 'disallowed_url';

	public function __construct(
		public readonly string $kind,
		string $message,
		public readonly ?int $status = null,
		public readonly int $attempts = 0,
	) {
		parent::__construct($message);
	}

	public static function fromStatus(int $status, int $attempts, string $body = ''): self
	{
		// Figma zwraca 403 także dla tokenu bez wymaganego zakresu — wtedy dostęp do pliku nie jest problemem.
		if ($status === 403 && str_contains($body, 'Invalid scope')) {
			return new self(self::FORBIDDEN, 'Token Figma API nie ma zakresu file_content:read (403). Wygeneruj token z tym zakresem.', $status, $attempts);
		}

		return match (true) {
			$status === 400 => new self(self::BAD_REQUEST, 'Figma odrzuciła zapytanie (400). Sprawdź link do frame\'u.', $status, $attempts),
			$status === 401 => new self(self::UNAUTHORIZED, 'Token Figma API jest nieprawidłowy lub wygasł (401).', $status, $attempts),
			$status === 403 => new self(self::FORBIDDEN, 'Brak dostępu do tego pliku Figmy (403). Token musi należeć do konta z dostępem do pliku.', $status, $attempts),
			$status === 404 => new self(self::NOT_FOUND, 'Nie znaleziono pliku lub frame\'u w Figmie (404).', $status, $attempts),
			$status === 429 => new self(self::RATE_LIMITED, 'Przekroczono limit zapytań Figma API (429). Spróbuj ponownie za kilka minut.', $status, $attempts),
			$status >= 500 => new self(self::SERVER, "Figma API jest chwilowo niedostępne ({$status}). Spróbuj ponownie później.", $status, $attempts),
			default => new self(self::INVALID_RESPONSE, "Nieoczekiwana odpowiedź Figma API ({$status}).", $status, $attempts),
		};
	}
}
