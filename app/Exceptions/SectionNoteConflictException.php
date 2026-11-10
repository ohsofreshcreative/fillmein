<?php

namespace App\Exceptions;

use App\Models\SectionNote;
use RuntimeException;

/**
 * Uwagi sekcji zmienione w międzyczasie przez kogoś innego (inna wersja bazowa).
 */
class SectionNoteConflictException extends RuntimeException
{
	public function __construct(public readonly SectionNote $current)
	{
		parent::__construct('Uwagi tej sekcji zostały zmienione w międzyczasie.');
	}
}
