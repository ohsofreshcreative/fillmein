<?php

namespace App\Enums;

/**
 * Pochodzenie podstrony, sekcji lub pola.
 *
 * Import z Figmy zarządza wyłącznie elementami `figma` (dopasowanie po figma_node_id).
 * Elementy `manual` nigdy nie są przez import usuwane ani nadpisywane.
 */
enum ElementSource: string
{
	case Figma = 'figma';
	case Manual = 'manual';

	public function label(): string
	{
		return match ($this) {
			self::Figma => 'Figma',
			self::Manual => 'Ręcznie',
		};
	}
}
