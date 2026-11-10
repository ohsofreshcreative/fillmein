<?php

namespace App\Support;

/**
 * Odczyt schematu strony (`fmi-site-schema`): bloki, strony i pola ACF z pełnymi ścieżkami.
 *
 * Ścieżka pola liczona jest od korzenia bloku tak, jak czyta ją wtyczka Fill Me In Sync: grupa dokłada
 * `nazwa.`, repeater `nazwa.*.` (np. `g_top.header`, `r_list.*.r_cards.*.icon`). `scope` to klucz najbliższego
 * repeatera nad polem (null — korzeń bloku): pola z repeatera Fill Me In wolno dopasować tylko w zakresie
 * repeatera ACF, z którym sparowano ten repeater. Pola wewnątrz `flexible_content` nie są dopasowywane
 * (wtyczka ich nie importuje).
 */
final class SiteSchemaIndex
{
	/** @var array<string, array> */
	private array $blocks = [];

	/** @var array<string, array> */
	private array $pages = [];

	/** @var array<string, array<string, array>> blok → klucz → wpis */
	private array $fields = [];

	public function __construct(public readonly array $document)
	{
		foreach ((array) ($document['blocks'] ?? []) as $block) {
			if (is_array($block) && is_string($block['name'] ?? null)) {
				$this->blocks[$block['name']] = $block;
			}
		}

		foreach ((array) ($document['pages'] ?? []) as $page) {
			if (is_array($page) && isset($page['id'])) {
				$this->pages[(string) $page['id']] = $page;
			}
		}
	}

	/** @return array<string, array> */
	public function blocks(): array
	{
		return $this->blocks;
	}

	public function block(?string $name): ?array
	{
		return $name === null ? null : ($this->blocks[$name] ?? null);
	}

	/** @return array<string, array> */
	public function pages(): array
	{
		return $this->pages;
	}

	public function page(?string $id): ?array
	{
		return $id === null ? null : ($this->pages[$id] ?? null);
	}

	/**
	 * Bloki ACF strony schematu wg pozycji.
	 *
	 * @return array<int, array{position: int, name: string, occurrence: int}>
	 */
	public function pageBlocks(?string $pageId): array
	{
		$blocks = array_values(array_filter(
			(array) ($this->page($pageId)['blocks'] ?? []),
			fn ($block) => is_array($block) && ! empty($block['acf']) && is_string($block['name'] ?? null),
		));
		usort($blocks, fn ($a, $b) => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));

		return array_map(fn ($block) => [
			'position' => (int) ($block['position'] ?? 0),
			'name' => (string) $block['name'],
			'occurrence' => (int) ($block['occurrence'] ?? 0),
		], $blocks);
	}

	public function pageBlockAt(?string $pageId, ?int $position): ?array
	{
		if ($position === null) {
			return null;
		}

		foreach ($this->pageBlocks($pageId) as $block) {
			if ($block['position'] === $position) {
				return $block;
			}
		}

		return null;
	}

	/**
	 * Wszystkie pola bloku (w kolejności drzewa) z wyliczoną ścieżką.
	 *
	 * @return array<string, array{key: string, name: string, label: string, type: string, path: string, scope: ?string, groups: string[], required: bool, display: string, def: array}>
	 */
	public function fields(?string $block): array
	{
		if ($block === null || ! isset($this->blocks[$block])) {
			return [];
		}

		if (! isset($this->fields[$block])) {
			$this->fields[$block] = [];
			$this->walk($block, (array) ($this->blocks[$block]['fields'] ?? []), '', null, [], '', '');
		}

		return $this->fields[$block];
	}

	public function field(?string $block, ?string $key): ?array
	{
		return $key === null ? null : ($this->fields($block)[$key] ?? null);
	}

	/**
	 * Pola jednego zakresu (korzeń bloku albo wnętrze repeatera), także zagnieżdżone w grupach.
	 *
	 * @return array<string, array>
	 */
	public function scopeFields(?string $block, ?string $scope): array
	{
		return array_filter($this->fields($block), fn ($field) => $field['scope'] === $scope);
	}

	private function walk(string $block, array $fields, string $prefix, ?string $scope, array $groups, string $labelPrefix, string $namePrefix): void
	{
		foreach ($fields as $def) {
			if (! is_array($def) || ! is_string($def['name'] ?? null) || $def['name'] === '' || ! is_string($def['key'] ?? null)) {
				continue;
			}

			$type = (string) ($def['type'] ?? 'text');
			$label = trim((string) ($def['label'] ?? '')) ?: $def['name'];
			$path = $prefix . $def['name'];

			$this->fields[$block][$def['key']] = [
				'key' => $def['key'],
				'name' => $def['name'],
				'label' => $label,
				'type' => $type,
				'path' => $path,
				'scope' => $scope,
				'groups' => $groups,
				'required' => ! empty($def['required']),
				'display' => sprintf('%s%s (%s%s)', $labelPrefix, $label, $namePrefix, $def['name']),
				'def' => $def,
			];

			$subs = (array) ($def['sub_fields'] ?? []);

			if ($type === 'group') {
				$this->walk($block, $subs, $path . '.', $scope, [...$groups, $def['key']], $labelPrefix . $label . ' › ', $namePrefix . $def['name'] . ' › ');
			} elseif ($type === 'repeater') {
				$this->walk($block, $subs, $path . '.*.', $def['key'], [], $labelPrefix . $label . ' › ', $namePrefix . $def['name'] . ' › ');
			}
		}
	}
}
