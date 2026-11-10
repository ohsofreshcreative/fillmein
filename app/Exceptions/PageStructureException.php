<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Naruszenie reguł struktury podstron (cykl, limit zagnieżdżenia, obcy projekt,
 * brak zgody na duplikowanie). Kontroler zamienia je na komunikat walidacji.
 */
class PageStructureException extends RuntimeException
{
	public function __construct(string $message, public readonly string $field = 'parent_id')
	{
		parent::__construct($message);
	}
}
