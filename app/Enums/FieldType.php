<?php

namespace App\Enums;

/**
 * Typy pól treści.
 *
 * Sposób przechowywania wartości (fmi_field_values.value):
 * - text, textarea, wysiwyg — tekst,
 * - link                    — JSON {"label": "…", "url": "…"} (przycisk / odnośnik),
 * - image, gallery          — JSON z listą UUID plików (fmi_assets) w kolejności; spójność
 *                             zapewnia dodatkowo tabela fmi_field_value_assets,
 * - repeater                — JSON z listą UUID wierszy (fmi_repeater_rows) w kolejności,
 * - group                   — brak własnej wartości (tylko pola potomne).
 *
 * Każda wartość z listą (gallery, repeater) jest wersjonowana tak samo jak tekst,
 * więc zmiana kolejności / dodanie / usunięcie elementu też podlega ochronie przed konfliktem.
 */
enum FieldType: string
{
	case Text = 'text';
	case Textarea = 'textarea';
	case Wysiwyg = 'wysiwyg';
	case Link = 'link';
	case Image = 'image';
	case Gallery = 'gallery';
	case Group = 'group';
	case Repeater = 'repeater';

	public function label(): string
	{
		return match ($this) {
			self::Text => 'Tekst',
			self::Textarea => 'Tekst wielowierszowy',
			self::Wysiwyg => 'Edytor WYSIWYG',
			self::Link => 'Link (tekst i adres)',
			self::Image => 'Zdjęcie',
			self::Gallery => 'Galeria',
			self::Group => 'Grupa',
			self::Repeater => 'Repeater',
		};
	}

	/**
	 * Pola mogące mieć pola potomne.
	 */
	public function isContainer(): bool
	{
		return in_array($this, [self::Group, self::Repeater], true);
	}

	public function isText(): bool
	{
		return in_array($this, [self::Text, self::Textarea, self::Wysiwyg], true);
	}

	/**
	 * Pola z pojedynczą wartością zapisywaną przez ContentService::save (tekst lub JSON linku).
	 */
	public function storesPlainValue(): bool
	{
		return $this->isText() || $this === self::Link;
	}

	public function isMedia(): bool
	{
		return in_array($this, [self::Image, self::Gallery], true);
	}

	/**
	 * Klucze `settings` konfigurowalne w panelu dla danego typu (etap 06).
	 *
	 * @return string[]
	 */
	public function settingKeys(): array
	{
		return match ($this) {
			self::Text, self::Textarea => ['max_length', 'placeholder'],
			self::Wysiwyg => ['max_length'],
			self::Image => ['min_width', 'min_height', 'require_alt'],
			self::Gallery => ['min_items', 'max_items', 'min_width', 'min_height', 'require_alt'],
			self::Repeater => ['min_items', 'max_items'],
			self::Link, self::Group => [],
		};
	}

	/**
	 * Czy zapisana treść zachowuje sens po zmianie typu (tekstowe ↔ tekstowe, zdjęcie → galeria).
	 * Pozostałe zmiany wymagają potwierdzenia i czyszczą treść (zostaje w historii rewizji).
	 */
	public function keepsValueAs(self $to): bool
	{
		return $this === $to
			|| ($this->isText() && $to->isText())
			|| ($this === self::Image && $to === self::Gallery);
	}

	/**
	 * Typy pól z warstw Figmy. Grupy i repeatery import proponuje dla kontenerów i powtarzalnych
	 * kart; galerie tworzy się ręcznie.
	 */
	public static function importable(): array
	{
		return [self::Text, self::Textarea, self::Wysiwyg, self::Link, self::Image];
	}
}
