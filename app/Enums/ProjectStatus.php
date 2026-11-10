<?php

namespace App\Enums;

enum ProjectStatus: string
{
	case Draft = 'draft';
	case Collecting = 'collecting';
	case Review = 'review';
	case Completed = 'completed';
	case Archived = 'archived';

	public function label(): string
	{
		return match ($this) {
			self::Draft => 'Konfiguracja',
			self::Collecting => 'Zbieranie treści',
			self::Review => 'Weryfikacja',
			self::Completed => 'Zakończony',
			self::Archived => 'Archiwum',
		};
	}

	/**
	 * Wariant komponentu <x-ui.badge>.
	 */
	public function badge(): string
	{
		return match ($this) {
			self::Draft => 'neutral',
			self::Collecting => 'fresh',
			self::Review => 'warning',
			self::Completed => 'success',
			self::Archived => 'muted',
		};
	}
}
