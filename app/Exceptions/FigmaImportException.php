<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Import struktury z Figmy niemożliwy (brak pobranego frame'u, nieaktualny podgląd, obca warstwa).
 * Komunikat jest przeznaczony dla pracownika.
 */
class FigmaImportException extends RuntimeException
{
}
