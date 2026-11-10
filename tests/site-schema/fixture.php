<?php

/**
 * Schemat strony testowej (format `fmi-site-schema` z wtyczki Fill Me In Sync).
 *
 * Bloki: hero (grupa g_top + link + zdjęcie), slider (grupa + repeater), features (repeater w repeaterze),
 * cta (płaskie pola), text (wysiwyg). Strona „strona-glowna”: hero, slider, cta, cta, text.
 */

$f = fn (string $block, string $name, string $label, string $type, array $extra = []) => $extra + ['key' => "field_{$block}_{$name}", 'name' => $name, 'label' => $label, 'type' => $type];

return [
	'format' => 'fmi-site-schema',
	'schema_version' => '1.0',
	'generator' => ['name' => 'fill-me-in-sync', 'version' => '1.0.0'],
	'exported_at' => '2026-11-09T12:00:00+00:00',
	'site' => ['url' => 'https://greenq.example/', 'name' => 'Green-Q test', 'theme' => 'greenq'],
	'post_types' => [['name' => 'page', 'label' => 'Strony', 'hierarchical' => true]],
	'blocks' => [
		['name' => 'acf/hero', 'title' => 'Hero', 'fields' => [
			$f('hero', 'g_top', 'Nagłówek', 'group', ['sub_fields' => [
				$f('hero', 'g_top_header', 'Tytuł', 'text', ['name' => 'header', 'required' => true]),
				$f('hero', 'g_top_text', 'Opis', 'textarea', ['name' => 'text']),
			]]),
			$f('hero', 'button', 'Przycisk', 'link'),
			$f('hero', 'image', 'Zdjęcie', 'image', ['return_format' => 'array']),
		]],
		['name' => 'acf/slider', 'title' => 'Slider', 'fields' => [
			$f('slider', 'g_top', 'Nagłówek', 'group', ['sub_fields' => [
				$f('slider', 'g_top_header', 'Tytuł', 'text', ['name' => 'header']),
				$f('slider', 'g_top_text', 'Opis', 'textarea', ['name' => 'text']),
			]]),
			$f('slider', 'r_cards', 'Karty', 'repeater', ['sub_fields' => [
				$f('slider', 'r_cards_icon', 'Ikona', 'image', ['name' => 'icon']),
				$f('slider', 'r_cards_title', 'Tytuł', 'text', ['name' => 'title']),
			]]),
		]],
		['name' => 'acf/features', 'title' => 'Zalety', 'fields' => [
			$f('features', 'header', 'Nagłówek', 'text'),
			$f('features', 'r_list', 'Lista', 'repeater', ['sub_fields' => [
				$f('features', 'r_list_title', 'Tytuł', 'text', ['name' => 'title']),
				$f('features', 'r_list_r_cards', 'Karty', 'repeater', ['name' => 'r_cards', 'sub_fields' => [
					$f('features', 'r_list_r_cards_icon', 'Ikona', 'image', ['name' => 'icon']),
					$f('features', 'r_list_r_cards_title', 'Tytuł', 'text', ['name' => 'title']),
				]]),
			]]),
		]],
		['name' => 'acf/cta', 'title' => 'Wezwanie do działania', 'fields' => [
			$f('cta', 'header', 'Nagłówek', 'text'),
			$f('cta', 'button', 'Przycisk', 'link'),
		]],
		['name' => 'acf/text', 'title' => 'Tekst', 'fields' => [
			$f('text', 'content', 'Treść', 'wysiwyg'),
		]],
		['name' => 'acf/faq', 'title' => 'FAQ', 'fields' => [
			$f('faq', 'layouts', 'Układy', 'flexible_content', ['layouts' => [['name' => 'q', 'label' => 'Pytanie', 'sub_fields' => [$f('faq', 'q_text', 'Pytanie', 'text')]]]]),
		]],
	],
	'pages' => [
		['id' => 12, 'post_type' => 'page', 'slug' => 'strona-glowna', 'path' => 'strona-glowna', 'parent_path' => null, 'title' => 'Strona główna', 'status' => 'publish', 'template' => null, 'blocks' => [
			['position' => 0, 'name' => 'acf/hero', 'occurrence' => 0, 'acf' => true],
			['position' => 1, 'name' => 'acf/slider', 'occurrence' => 0, 'acf' => true],
			['position' => 2, 'name' => 'core/paragraph', 'occurrence' => 0, 'acf' => false],
			['position' => 3, 'name' => 'acf/cta', 'occurrence' => 0, 'acf' => true],
			['position' => 4, 'name' => 'acf/cta', 'occurrence' => 1, 'acf' => true],
			['position' => 5, 'name' => 'acf/text', 'occurrence' => 0, 'acf' => true],
		]],
		['id' => 20, 'post_type' => 'page', 'slug' => 'o-firmie', 'path' => 'o-firmie', 'parent_path' => null, 'title' => 'O firmie Green-Q', 'status' => 'publish', 'template' => 'template-about.blade.php', 'blocks' => [
			['position' => 0, 'name' => 'acf/text', 'occurrence' => 0, 'acf' => true],
		]],
	],
];
