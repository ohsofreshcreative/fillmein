<?php

namespace App\Services;

/**
 * Wynik wysyłki zaproszenia. `sent = false` oznacza, że wiadomość nie wyszła — zaproszenie
 * nie jest wtedy oznaczane jako wysłane.
 */
final class InvitationResult
{
	public function __construct(
		public readonly bool $sent,
		public readonly ?string $error = null,
	) {
	}
}
