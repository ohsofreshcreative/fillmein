<?php

namespace App\Exceptions;

use App\Models\FieldValue;
use RuntimeException;

/**
 * Zapis oparty na nieaktualnej wersji treści — ktoś zapisał nowszą zmianę w międzyczasie.
 * Endpoint zwraca 409 z obiema wersjami: `$current` zostaje w bazie, `$attempted` wraca do autora.
 */
class ContentConflictException extends RuntimeException
{
	public function __construct(public readonly FieldValue $current, public readonly ?string $attempted = null)
	{
		parent::__construct('Treść pola została zmieniona w międzyczasie.');
	}
}
