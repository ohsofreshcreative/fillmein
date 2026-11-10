<?php

namespace App\Support;

use App\Enums\ElementSource;
use App\Enums\FieldType;
use App\Models\Field;
use App\Models\Section;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Nazwy pokazywane klientowi. Warstwy z Figmy mają nazwy robocze („slider”, „top”, „Frame 312”), a w bazie muszą
 * zostać bez zmian, bo diff, mapowanie ACF i eksport opierają się na nich. Dlatego tłumaczymy je dopiero przy
 * wyświetlaniu. Nazwa nadana przez zespół (element ręczny albo zmiana etykiety) przechodzi bez zmian.
 */
final class ClientLabels
{
	/** Stopień pisma (px), od którego samotny tekst jest nagłówkiem. */
	private const HEADING_MIN_SIZE = 24;

	/** Długość tekstu, od której pole jest opisem, nawet gdy Figma ma go w jednym wierszu. */
	private const LONG_TEXT = 60;

	private const SECTIONS = [
		'menu' => 'Menu',
		'nav' => 'Menu',
		'navigation' => 'Menu',
		'navbar' => 'Menu',
		'header' => 'Nagłówek strony',
		'footer' => 'Stopka',
	];

	private const LISTS = [
		'btns' => 'Przyciski',
		'buttons' => 'Przyciski',
		'cards' => 'Karty',
		'items' => 'Elementy',
		'products' => 'Produkty',
		'slides' => 'Slajdy',
		'tabs' => 'Zakładki',
	];

	public static function isTechnical(?string $name): bool
	{
		$name = trim((string) $name);

		return $name === ''
			|| str_starts_with($name, 'Lista: ')
			|| preg_match('/^[a-z0-9_.\/-]+$/', $name) === 1
			|| preg_match('/^(frame|group|section|component|instance|rectangle|container|layer|item|text)[ _-]*\d*$/i', $name) === 1;
	}

	public static function section(Section $section, ClientStructure $structure): string
	{
		if ($section->source === ElementSource::Manual || ! self::isTechnical($section->name)) {
			return $section->name;
		}

		$known = self::SECTIONS[strtok(mb_strtolower($section->name), '/')] ?? null;
		$number = $structure->sections->search(fn (Section $item) => $item->id === $section->id) + 1;

		return $known ?? self::firstText($section, $structure) ?? 'Sekcja ' . $number;
	}

	public static function field(Field $field): string
	{
		return self::siblings(collect([$field]))[$field->id];
	}

	/**
	 * Etykiety pól jednego poziomu. Tekst z Figmy nie opisuje pola („Akustyka” to przykładowa treść), dlatego
	 * rolę bierzemy ze stopnia pisma i kolejności: największy tekst to nagłówek, mniejszy nad nim to etykieta.
	 *
	 * @param Collection<int, Field> $fields w kolejności wyświetlania
	 * @return array<int, string> klucz: ID pola
	 */
	public static function siblings(Collection $fields): array
	{
		$size = fn (Field $field) => (float) ($field->figma_meta['text_style']['fontSize'] ?? 0);
		$texts = $fields->filter(fn (Field $field) => self::isAutomatic($field) && in_array($field->type, [FieldType::Text, FieldType::Textarea], true));
		$largest = $texts->max($size);
		$top = $texts->filter(fn (Field $field) => $size($field) === (float) $largest);
		$heading = $top->count() <= 2 && ((float) $largest >= self::HEADING_MIN_SIZE || ($texts->count() > 1 && $top->count() === 1))
			? $top->first()
			: null;
		$passed = false;
		$labels = [];

		foreach ($fields as $field) {
			if (! self::isAutomatic($field)) {
				$labels[$field->id] = $field->label;
			} elseif ($field->is($heading)) {
				$labels[$field->id] = 'Nagłówek';
				$passed = true;
			} elseif ($texts->contains($field)) {
				$labels[$field->id] = match (true) {
					$field->type === FieldType::Textarea, mb_strlen((string) $field->figma_text) >= self::LONG_TEXT => 'Opis',
					$heading && ! $passed => 'Etykieta',
					(bool) $heading => 'Podtytuł',
					default => 'Tekst',
				};
			} else {
				$labels[$field->id] = self::byType($field);
			}
		}

		return self::numbered($labels, $fields);
	}

	/**
	 * Treść z projektu jako podpowiedź w pustym polu: klient widzi, co tu ma się znaleźć.
	 */
	public static function example(Field $field): ?string
	{
		$text = trim((string) preg_replace('/\s+/u', ' ', (string) $field->figma_text));

		return $text !== '' ? 'np. ' . Str::limit($text, 90) : null;
	}

	/**
	 * Grupa o roboczej nazwie to tylko układ warstw w Figmie: klient widzi jej pola bez ramki i podpisu.
	 */
	public static function isPlainGroup(Field $field): bool
	{
		return $field->type === FieldType::Group
			&& $field->source !== ElementSource::Manual
			&& self::isTechnical($field->label);
	}

	/**
	 * Nazwa automatyczna: z importu i niezmieniona przez zespół.
	 */
	private static function isAutomatic(Field $field): bool
	{
		return $field->source !== ElementSource::Manual
			&& (self::isTechnical($field->label) || $field->label === ($field->figma_meta['proposed']['label'] ?? null))
			&& ! in_array($field->label, ['Zdjęcie', 'Ikona'], true);
	}

	private static function byType(Field $field): string
	{
		$name = mb_strtolower(trim((string) preg_replace('/^Lista: /', '', $field->label)));

		return match ($field->type) {
			FieldType::Repeater => self::LISTS[$name] ?? (self::isTechnical($field->label) ? 'Lista elementów' : $field->label),
			FieldType::Group => self::isTechnical($field->label) ? 'Grupa pól' : $field->label,
			FieldType::Wysiwyg => 'Treść',
			FieldType::Link => 'Przycisk',
			FieldType::Image => 'Zdjęcie',
			FieldType::Gallery => 'Galeria',
			default => 'Tekst',
		};
	}

	/**
	 * Kilka pól o tej samej roli na poziomie dostaje numery, żeby dało się je odróżnić.
	 */
	private static function numbered(array $labels, Collection $fields): array
	{
		$automatic = $fields->filter(fn (Field $field) => self::isAutomatic($field))->pluck('id')->all();
		$counts = array_count_values(array_intersect_key($labels, array_flip($automatic)));
		$seen = [];

		foreach ($automatic as $id) {
			$label = $labels[$id];

			if ($counts[$label] > 1) {
				$seen[$label] = ($seen[$label] ?? 0) + 1;
				$labels[$id] = $label . ' ' . $seen[$label];
			}
		}

		return $labels;
	}

	/**
	 * Pierwszy tekst sekcji to zwykle jej nagłówek w projekcie, po którym klient ją rozpozna.
	 */
	private static function firstText(Section $section, ClientStructure $structure, ?Field $parent = null): ?string
	{
		foreach ($structure->children($section, $parent) as $field) {
			if ($field->type->isText() && ! self::isTechnical($field->label)) {
				return Str::limit($field->label, 60);
			}

			if ($field->type->isContainer() && ($found = self::firstText($section, $structure, $field))) {
				return $found;
			}
		}

		return null;
	}
}
