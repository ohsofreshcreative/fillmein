<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Jednoczesna zmiana w Design Review (status albo edycja wiadomości): ktoś zmienił rekord po tym, jak
 * użytkownik go wczytał. Nic nie zapisano; `current` opisuje bieżący stan, żeby pokazać go zamiast nadpisywać.
 */
class ReviewConflictException extends RuntimeException
{
	/**
	 * @param array<string, mixed> $current
	 */
	public function __construct(string $message, public readonly array $current = [])
	{
		parent::__construct($message);
	}
}
