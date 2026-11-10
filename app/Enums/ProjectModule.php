<?php

namespace App\Enums;

/**
 * Moduły wspólnego projektu. Projekt ma co najmniej jeden; kolumny `fmi_projects.<module>_enabled`.
 */
enum ProjectModule: string
{
	case Content = 'content';
	case Review = 'review';

	public function label(): string
	{
		return match ($this) {
			self::Content => 'Treści',
			self::Review => 'Design Review',
		};
	}

	public function description(): string
	{
		return match ($this) {
			self::Content => 'Zbieranie treści od klienta na podstawie podstron i pól.',
			self::Review => 'Przegląd projektu graficznego z klientem: rewizje, komentarze i akceptacje.',
		};
	}

	public function column(): string
	{
		return $this->value . '_enabled';
	}
}
