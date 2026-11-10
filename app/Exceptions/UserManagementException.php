<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Operacja na koncie niedozwolona regułami biznesowymi (np. odebranie dostępu ostatniemu
 * administratorowi, zmiana własnej roli). Komunikat jest bezpieczny do pokazania użytkownikowi.
 */
class UserManagementException extends RuntimeException
{
	public function __construct(string $message, public readonly ?string $field = null)
	{
		parent::__construct($message);
	}
}
