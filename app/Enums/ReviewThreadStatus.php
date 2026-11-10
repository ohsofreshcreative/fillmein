<?php

namespace App\Enums;

/**
 * Status wątku uwag. Wartość `open` (z etapu 04) oznacza „Nowy” — bez migracji danych.
 * „Otwarte uwagi” w licznikach = Nowy + W trakcie (wszystko poza Rozwiązany).
 */
enum ReviewThreadStatus: string
{
	case Open = 'open';
	case InProgress = 'in_progress';
	case Resolved = 'resolved';

	public function label(): string
	{
		return match ($this) {
			self::Open => 'Nowy',
			self::InProgress => 'W trakcie',
			self::Resolved => 'Rozwiązany',
		};
	}

	public function badge(): string
	{
		return match ($this) {
			self::Open => 'fresh',
			self::InProgress => 'warning',
			self::Resolved => 'success',
		};
	}

	/**
	 * Parametr filtra w adresie listy (`?status=`).
	 */
	public function filter(): string
	{
		return match ($this) {
			self::Open => 'nowe',
			self::InProgress => 'w-trakcie',
			self::Resolved => 'rozwiazane',
		};
	}

	public static function fromFilter(?string $value): ?self
	{
		foreach (self::cases() as $case) {
			if ($case->filter() === $value) {
				return $case;
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	public static function unresolved(): array
	{
		return [self::Open->value, self::InProgress->value];
	}
}
