<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Naruszenie reguł konfiguracji sekcji i pól (obca sekcja lub pole nadrzędne, limit zagnieżdżenia,
 * niedozwolona zmiana typu, zajęta nazwa techniczna). Kontroler zamienia je na komunikat walidacji.
 *
 * `requiresConfirmation` — zmiana typu jest możliwa, ale usuwa bieżącą treść (zostaje w historii rewizji).
 */
class FieldConfigException extends RuntimeException
{
	public function __construct(
		string $message,
		public readonly string $field = 'type',
		public readonly bool $requiresConfirmation = false,
	) {
		parent::__construct($message);
	}
}
