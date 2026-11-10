<?php

namespace App\Services;

use App\Support\SiteSchemaIndex;

/**
 * Automatyczne dopasowanie projektu do schematu strony (bez bazy danych — wejście i wyjście to tablice).
 *
 * Wejście `$project`:
 *   pages: [{id, name, slug, post_type, sections: [{id, name, hidden, fields: [node]}]}]
 *   node:  {id, label, key, type (typ Fill Me In), text (tekst z Figmy|null), children: [node]}
 * Wejście `$fixed` (ręczne dopasowania, automat ich nie zmienia i nie zajmuje ich celów):
 *   pages: [id => ID strony schematu|null], sections: [id => {block, position}|null], fields: [id => klucz ACF|null]
 *
 * Wyjście (tylko elementy bez ręcznego dopasowania):
 *   pages: [id => {target, confidence}], sections: [id => {block, position, confidence}], fields: [id => {key, confidence}]
 *
 * Kolejność to główny sygnał, więc sekcje ↔ bloki strony i pola ↔ pola ACF są wyrównywane jak sekwencje
 * (Needleman-Wunsch bez kary za przerwę): dodatkowa albo brakująca sekcja nie przesuwa pozostałych par.
 * Grupy są przezroczyste (wyrównujemy liście przez grupy po obu stronach), repeatery zachowują zagnieżdżenie.
 */
class SiteMatcher
{
	public const GREEN = 0.8;

	public const YELLOW = 0.5;

	/** Para słabsza niż próg nie opłaca się w wyrównaniu — lepiej zostawić element bez pary. */
	private const FIELD_THRESHOLD = 0.35;

	private const SECTION_THRESHOLD = 0.3;

	private const PAGE_TITLE_THRESHOLD = 0.7;

	private const ANY_BLOCK_THRESHOLD = 0.5;

	private SiteSchemaIndex $schema;

	private array $fixed = [];

	/** @var array<string, array> wynik wyrównania pól: "sekcja|blok" → {score, fields} */
	private array $memo = [];

	/**
	 * Zgodność typów Fill Me In → ACF (1 — ten sam typ, mniej — dopuszczalny zamiennik). Brak wpisu = niezgodne.
	 */
	public const TYPES = [
		'text' => ['text' => 1.0, 'textarea' => 0.9, 'wysiwyg' => 0.85, 'email' => 0.7, 'url' => 0.7, 'number' => 0.7, 'oembed' => 0.7],
		'textarea' => ['textarea' => 1.0, 'text' => 0.9, 'wysiwyg' => 0.9, 'email' => 0.65, 'url' => 0.65, 'number' => 0.65, 'oembed' => 0.65],
		'wysiwyg' => ['wysiwyg' => 1.0, 'textarea' => 0.9, 'text' => 0.85, 'email' => 0.6, 'url' => 0.6, 'number' => 0.6, 'oembed' => 0.6],
		'image' => ['image' => 1.0, 'file' => 0.8],
		'gallery' => ['gallery' => 1.0],
		'link' => ['link' => 1.0, 'url' => 0.55],
		'repeater' => ['repeater' => 1.0],
		'group' => ['group' => 1.0],
	];

	public static function compatibility(string $fmiType, string $acfType): ?float
	{
		return self::TYPES[$fmiType][$acfType] ?? null;
	}

	public function match(array $project, SiteSchemaIndex $schema, array $fixed = []): array
	{
		$this->schema = $schema;
		$this->fixed = $fixed + ['pages' => [], 'sections' => [], 'fields' => []];
		$this->memo = [];
		$result = ['pages' => [], 'sections' => [], 'fields' => []];

		$pagePairs = $this->matchPages($project['pages'] ?? []);

		foreach ($project['pages'] ?? [] as $page) {
			if (! array_key_exists($page['id'], $this->fixed['pages'])) {
				$result['pages'][$page['id']] = $pagePairs[$page['id']];
			}

			$schemaPage = array_key_exists($page['id'], $this->fixed['pages']) ? $this->fixed['pages'][$page['id']] : $pagePairs[$page['id']]['target'];
			$schemaPage = $schema->page($schemaPage === null ? null : (string) $schemaPage) ? (string) $schemaPage : null;

			$sections = $this->matchSections($page['sections'] ?? [], $schemaPage);

			foreach ($page['sections'] ?? [] as $section) {
				$fixedSection = array_key_exists($section['id'], $this->fixed['sections']);

				if (! $fixedSection) {
					$result['sections'][$section['id']] = $sections[$section['id']];
				}

				$block = $fixedSection ? ($this->fixed['sections'][$section['id']]['block'] ?? null) : $sections[$section['id']]['block'];
				$block = $schema->block($block) ? $block : null;

				$result['fields'] += $this->sectionFields($section, $block);
			}
		}

		return $result;
	}

	/**
	 * Dopasowanie pól sekcji do bloku (null — sekcja bez bloku: wszystkie pola bez pary).
	 *
	 * @return array<int, array{key: ?string, confidence: float}>
	 */
	public function sectionFields(array $section, ?string $block): array
	{
		$fields = [];

		if ($block !== null) {
			$fields = $this->alignSection($section, $block)['fields'];
		}

		$all = [];
		$this->collect($section['fields'] ?? [], $all);

		foreach ($all as $id) {
			if (! array_key_exists($id, $this->fixed['fields']) && ! isset($fields[$id])) {
				$fields[$id] = ['key' => null, 'confidence' => 0.0];
			}
		}

		return array_diff_key($fields, $this->fixed['fields']);
	}

	/* ---------------------------------------------------------------------
	 * Strony
	 * ------------------------------------------------------------------- */

	private function matchPages(array $pages): array
	{
		$used = [];

		foreach ($this->fixed['pages'] as $target) {
			if ($target !== null) {
				$used[(string) $target] = true;
			}
		}

		$result = [];
		$pending = [];

		// Najpierw pewne pary po slugu, potem podobieństwo nazwy do tytułu wśród wolnych stron schematu.
		foreach ($pages as $page) {
			if (array_key_exists($page['id'], $this->fixed['pages'])) {
				continue;
			}

			$type = $page['post_type'] ?? 'page';
			$found = null;

			foreach ($this->schema->pages() as $id => $candidate) {
				if (! isset($used[$id]) && ($candidate['post_type'] ?? 'page') === $type && (string) ($candidate['slug'] ?? '') !== '' && $candidate['slug'] === $page['slug']) {
					$found = $id;

					break;
				}
			}

			if ($found !== null) {
				$used[$found] = true;
				$result[$page['id']] = ['target' => (string) $found, 'confidence' => 1.0];
			} else {
				$pending[] = $page;
			}
		}

		foreach ($pending as $page) {
			$best = null;
			$bestScore = 0.0;

			foreach ($this->schema->pages() as $id => $candidate) {
				if (isset($used[$id]) || ($candidate['post_type'] ?? 'page') !== ($page['post_type'] ?? 'page')) {
					continue;
				}

				$score = self::similarity($page['name'], (string) ($candidate['title'] ?? ''));

				if ($score > $bestScore) {
					[$best, $bestScore] = [(string) $id, $score];
				}
			}

			if ($best !== null && $bestScore >= self::PAGE_TITLE_THRESHOLD) {
				$used[$best] = true;
				$result[$page['id']] = ['target' => $best, 'confidence' => round($bestScore, 3)];
			} else {
				$result[$page['id']] = ['target' => null, 'confidence' => 0.0];
			}
		}

		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Sekcje ↔ bloki
	 * ------------------------------------------------------------------- */

	private function matchSections(array $sections, ?string $schemaPage): array
	{
		$result = [];
		$visible = [];

		foreach ($sections as $section) {
			if (array_key_exists($section['id'], $this->fixed['sections'])) {
				continue;
			}

			if (! empty($section['hidden'])) {
				// Wtyczka pomija ukryte sekcje — nie zajmujemy dla nich bloków.
				$result[$section['id']] = ['block' => null, 'position' => null, 'confidence' => 0.0];
			} else {
				$visible[] = $section;
			}
		}

		$leftover = $visible;

		if ($schemaPage !== null) {
			$taken = [];

			// Bloki zajęte ręcznie przez sekcje tej strony (pozycje są lokalne dla strony schematu).
			foreach ($sections as $section) {
				$fixed = $this->fixed['sections'][$section['id']] ?? null;

				if (($fixed['position'] ?? null) !== null) {
					$taken[(int) $fixed['position']] = true;
				}
			}

			$blocks = array_values(array_filter($this->schema->pageBlocks($schemaPage), fn ($block) => ! isset($taken[$block['position']]) && $this->schema->block($block['name'])));

			$pairs = $this->align($visible, $blocks, function (array $section, array $block, int $i, int $j, int $n, int $m) {
				$struct = $this->alignSection($section, $block['name'])['score'];
				$order = self::order($i, $n, $j, $m);
				$name = $this->blockNameSimilarity($section['name'], $block['name']);
				$score = 0.45 * $struct + 0.4 * $order + 0.15 * $name;

				return $score >= self::SECTION_THRESHOLD ? $score : null;
			}, self::SECTION_THRESHOLD);

			$leftover = [];

			foreach ($visible as $i => $section) {
				if (isset($pairs[$i])) {
					[$j, $score] = $pairs[$i];
					$result[$section['id']] = ['block' => $blocks[$j]['name'], 'position' => $blocks[$j]['position'], 'confidence' => round(min(1, $score), 3)];
				} else {
					$leftover[] = $section;
				}
			}
		}

		// Sekcja bez bloku na stronie (nowa strona albo dodatkowa sekcja): najlepszy blok motywu, nowy na stronie.
		foreach ($leftover as $section) {
			$best = null;
			$bestScore = 0.0;

			foreach (array_keys($this->schema->blocks()) as $name) {
				$score = 0.8 * $this->alignSection($section, $name)['score'] + 0.2 * $this->blockNameSimilarity($section['name'], $name);

				if ($score > $bestScore) {
					[$best, $bestScore] = [$name, $score];
				}
			}

			$result[$section['id']] = $best !== null && $bestScore >= self::ANY_BLOCK_THRESHOLD
				? ['block' => $best, 'position' => null, 'confidence' => round($bestScore, 3)]
				: ['block' => null, 'position' => null, 'confidence' => 0.0];
		}

		return $result;
	}

	private function blockNameSimilarity(string $section, string $block): float
	{
		$title = (string) ($this->schema->block($block)['title'] ?? '');

		return max(self::similarity($section, preg_replace('#^acf/#', '', $block)), self::similarity($section, $title));
	}

	/* ---------------------------------------------------------------------
	 * Pola ↔ pola ACF
	 * ------------------------------------------------------------------- */

	/**
	 * @return array{score: float, fields: array<int, array{key: ?string, confidence: float}>}
	 */
	private function alignSection(array $section, string $block): array
	{
		return $this->memo[$section['id'] . '|' . $block] ??= $this->alignLevel($section['fields'] ?? [], $block, null);
	}

	/**
	 * Jeden poziom: dzieci sekcji ↔ pola korzenia bloku albo dzieci repeatera ↔ `sub_fields` repeatera ACF.
	 * Wynik `score` (0–1) mówi, jak dobrze pasuje struktura — używany do wyboru bloku i oceny repeaterów.
	 */
	private function alignLevel(array $nodes, string $block, ?string $scope): array
	{
		$leaves = [];
		$groups = [];
		$this->leaves($nodes, $leaves, $groups);

		$fixed = $this->fixed['fields'];
		$scopeFields = $this->schema->scopeFields($block, $scope);
		$fields = [];
		$score = 0.0;
		$usedKeys = [];

		foreach ($leaves as $leaf) {
			if (array_key_exists($leaf['id'], $fixed) && $fixed[$leaf['id']] !== null) {
				$usedKeys[$fixed[$leaf['id']]] = true;
			}
		}

		foreach ($groups as $group) {
			if (array_key_exists($group['id'], $fixed) && $fixed[$group['id']] !== null) {
				$usedKeys[$fixed[$group['id']]] = true;
			}
		}

		$left = array_values(array_filter($leaves, fn ($leaf) => ! array_key_exists($leaf['id'], $fixed)));
		$right = array_values(array_filter($scopeFields, fn ($field) => $field['type'] !== 'group' && $field['type'] !== 'flexible_content' && ! isset($usedKeys[$field['key']])));
		$children = [];

		$pairs = $this->align($left, $right, function (array $node, array $field, int $i, int $j, int $n, int $m) use ($block, &$children) {
			$type = self::compatibility($node['type'], $field['type']);

			if ($type === null) {
				return null;
			}

			$order = self::order($i, $n, $j, $m);
			$name = $this->fieldNameSimilarity($node, $field);

			if ($node['type'] === 'repeater') {
				$inner = $children[$node['id'] . '|' . $field['key']] ??= $this->alignLevel($node['children'] ?? [], $block, $field['key']);
				$score = 0.3 * $type + 0.2 * $order + 0.15 * $name + 0.35 * $inner['score'];
			} else {
				$score = 0.5 * $type + 0.3 * $order + 0.2 * $name;

				if ($name >= 0.8) {
					$score += 0.1;
				}
			}

			return min(1.0, $score);
		}, self::FIELD_THRESHOLD);

		foreach ($left as $i => $node) {
			if (isset($pairs[$i])) {
				[$j, $pairScore] = $pairs[$i];
				$fields[$node['id']] = ['key' => $right[$j]['key'], 'confidence' => round($pairScore, 3)];
				$score += $pairScore;

				if ($node['type'] === 'repeater') {
					$fields += $children[$node['id'] . '|' . $right[$j]['key']]['fields'];
				}
			} else {
				$fields[$node['id']] = ['key' => null, 'confidence' => 0.0];
			}
		}

		// Repeater dopasowany ręcznie: jego dzieci wyrównujemy w zakresie wskazanego repeatera ACF.
		foreach ($leaves as $leaf) {
			if ($leaf['type'] === 'repeater' && array_key_exists($leaf['id'], $fixed)) {
				$target = $this->schema->field($block, $fixed[$leaf['id']]);

				if ($target && $target['type'] === 'repeater' && $target['scope'] === $scope) {
					$inner = $this->alignLevel($leaf['children'] ?? [], $block, $target['key']);
					$fields += $inner['fields'];
					$score += 1.0;
				}
			} elseif (array_key_exists($leaf['id'], $fixed) && $fixed[$leaf['id']] !== null) {
				$score += 1.0;
			}
		}

		$fields += $this->groupFields($groups, $fields, $block, $scope, $usedKeys);

		$total = count($leaves) + count(array_filter($scopeFields, fn ($field) => $field['type'] !== 'group' && $field['type'] !== 'flexible_content'));

		return [
			'score' => $total === 0 ? 1.0 : min(1.0, 2 * $score / $total),
			'fields' => $fields,
		];
	}

	/**
	 * Grupa z Figmy dostaje grupę ACF, w której leży większość jej dopasowanych pól; inaczej jest przezroczysta
	 * (`key` null — w ścieżkach jej nie ma, a pola w środku i tak mają pary).
	 */
	private function groupFields(array $groups, array $fields, string $block, ?string $scope, array $usedKeys): array
	{
		$result = [];

		foreach ($groups as $group) {
			if (array_key_exists($group['id'], $this->fixed['fields'])) {
				continue;
			}

			$votes = [];
			$mapped = 0;
			$confidence = 0.0;

			foreach ($group['leaves'] as $leafId) {
				$key = array_key_exists($leafId, $this->fixed['fields']) ? $this->fixed['fields'][$leafId] : ($fields[$leafId]['key'] ?? null);
				$field = $this->schema->field($block, $key);

				if (! $field) {
					continue;
				}

				$mapped++;
				$confidence += $fields[$leafId]['confidence'] ?? 1.0;

				foreach ($field['groups'] as $groupKey) {
					$votes[$groupKey] = ($votes[$groupKey] ?? 0) + 1;
				}
			}

			arsort($votes);
			$best = null;

			foreach ($votes as $key => $count) {
				if (! isset($usedKeys[$key]) && $count * 2 >= $mapped && $this->schema->field($block, $key)['scope'] === $scope) {
					$best = $key;

					break;
				}
			}

			if ($best !== null) {
				$usedKeys[$best] = true;
			}

			$result[$group['id']] = ['key' => $best, 'confidence' => $mapped ? round($confidence / $mapped, 3) : 0.0];
		}

		return $result;
	}

	private function fieldNameSimilarity(array $node, array $field): float
	{
		$best = 0.0;

		foreach (array_filter([$node['label'] ?? null, $node['key'] ?? null, $node['text'] ?? null]) as $left) {
			foreach ([$field['label'], $field['name']] as $right) {
				$best = max($best, self::similarity((string) $left, (string) $right));
			}
		}

		return $best;
	}

	/**
	 * Liście poziomu: grupy rozwinięte (przezroczyste), repeatery jako całość.
	 */
	private function leaves(array $nodes, array &$leaves, array &$groups): void
	{
		foreach ($nodes as $node) {
			if (($node['type'] ?? '') === 'group') {
				$inner = [];
				$this->leaves($node['children'] ?? [], $inner, $groups);
				$groups[] = ['id' => $node['id'], 'leaves' => array_column($inner, 'id')];
				array_push($leaves, ...$inner);
			} else {
				$leaves[] = $node;
			}
		}
	}

	private function collect(array $nodes, array &$ids): void
	{
		foreach ($nodes as $node) {
			$ids[] = $node['id'];
			$this->collect($node['children'] ?? [], $ids);
		}
	}

	/* ---------------------------------------------------------------------
	 * Wyrównanie i podobieństwo
	 * ------------------------------------------------------------------- */

	/**
	 * Needleman-Wunsch bez kary za przerwę: maksymalizuje sumę (wynik pary − próg) przy zachowanej kolejności.
	 * `$score` zwraca null dla par niedozwolonych.
	 *
	 * @return array<int, array{0: int, 1: float}> indeks lewej → [indeks prawej, wynik pary]
	 */
	private function align(array $left, array $right, callable $score, float $threshold): array
	{
		$n = count($left);
		$m = count($right);

		if ($n === 0 || $m === 0) {
			return [];
		}

		$pair = [];
		$dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0.0));
		$move = [];

		for ($i = 1; $i <= $n; $i++) {
			for ($j = 1; $j <= $m; $j++) {
				$best = $dp[$i - 1][$j];
				$step = 'up';

				if ($dp[$i][$j - 1] > $best) {
					[$best, $step] = [$dp[$i][$j - 1], 'left'];
				}

				$value = $score($left[$i - 1], $right[$j - 1], $i - 1, $j - 1, $n, $m);

				if ($value !== null && $value > $threshold) {
					$pair[$i][$j] = $value;

					if ($dp[$i - 1][$j - 1] + $value - $threshold > $best) {
						[$best, $step] = [$dp[$i - 1][$j - 1] + $value - $threshold, 'pair'];
					}
				}

				$dp[$i][$j] = $best;
				$move[$i][$j] = $step;
			}
		}

		$result = [];

		for ($i = $n, $j = $m; $i > 0 && $j > 0;) {
			switch ($move[$i][$j]) {
				case 'pair':
					$result[$i - 1] = [$j - 1, $pair[$i][$j]];
					$i--;
					$j--;

					break;
				case 'up':
					$i--;

					break;
				default:
					$j--;
			}
		}

		return $result;
	}

	/** Zgodność względnych pozycji na dwóch listach (1 — to samo miejsce). */
	public static function order(int $i, int $n, int $j, int $m): float
	{
		return 1 - abs(($i + 0.5) / $n - ($j + 0.5) / $m);
	}

	/** Małe litery, bez polskich znaków, wszystko poza [a-z0-9] → spacja. */
	public static function normalize(string $text): string
	{
		$text = mb_strtolower($text);
		$text = strtr($text, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);

		if (function_exists('remove_accents')) {
			$text = remove_accents($text);
		}

		return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
	}

	/** Współczynnik Dice'a na bigramach znormalizowanych tekstów (0–1). */
	public static function similarity(string $a, string $b): float
	{
		$a = self::normalize($a);
		$b = self::normalize($b);

		if ($a === '' || $b === '') {
			return 0.0;
		}

		if ($a === $b) {
			return 1.0;
		}

		$bigrams = function (string $text): array {
			$result = [];

			foreach (explode(' ', $text) as $word) {
				if (strlen($word) === 1) {
					$result[$word] = ($result[$word] ?? 0) + 1;
				}

				for ($k = 0; $k < strlen($word) - 1; $k++) {
					$gram = substr($word, $k, 2);
					$result[$gram] = ($result[$gram] ?? 0) + 1;
				}
			}

			return $result;
		};

		$x = $bigrams($a);
		$y = $bigrams($b);
		$common = 0;

		foreach ($x as $gram => $count) {
			$common += min($count, $y[$gram] ?? 0);
		}

		$total = array_sum($x) + array_sum($y);

		return $total === 0 ? 0.0 : 2 * $common / $total;
	}
}
