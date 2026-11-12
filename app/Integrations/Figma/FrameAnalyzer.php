<?php

namespace App\Integrations\Figma;

use App\Enums\FieldType;
use Illuminate\Support\Str;

/**
 * Analiza przyciętego drzewa frame'u (FigmaFrameService::prune) → propozycja sekcji i pól.
 * Czysta logika bez bazy i sieci; zapis i porównanie ze stanem podstrony: App\Services\FigmaImportService.
 *
 * - Sekcje: widoczne dzieci frame'u w kolejności czytania (góra → dół, lewo → prawo). Jeden kontener
 *   obejmujący prawie cały frame (wrapper) jest rozpakowywany.
 * - Pola: warstwy TEXT → text / textarea / wysiwyg / link (z uzasadnieniem), warstwy ze zdjęciem i ikony
 *   w elementach listy → image. Pośrednie kontenery
 *   o nazwach domyślnych („Frame 12”, „Kontener”) są spłaszczane.
 * - Repeater: co najmniej `min_repeat_items` sąsiednich kontenerów o identycznej strukturze tekstów
 *   (karty; dwa kontenery o różnych nazwach, np. „txt” i „btns”, to nie powtórzenie) albo `min_text_run` sąsiednich tekstów o tej samej nazwie (punkty listy). Grupa: nazwany
 *   kontener z ≥ 2 tekstami. Gdy struktura nie jest jednoznaczna, pola zostają płaskie — nie zgadujemy.
 * - Wykluczenia: ukryte, nawigacja, stopka, breadcrumbs, warstwy techniczne i puste teksty. Reguły
 *   pracownika (`include` / `exclude` per ID warstwy) mają pierwszeństwo przed regułami automatycznymi.
 */
final class FrameAnalyzer
{
	public const REASONS = [
		'hidden' => 'Warstwa ukryta w Figmie',
		'navigation' => 'Nawigacja / menu',
		'footer' => 'Stopka',
		'breadcrumbs' => 'Breadcrumbs',
		'technical' => 'Warstwa techniczna',
		'empty' => 'Pusta warstwa tekstowa',
		'manual' => 'Wykluczona przez pracownika',
	];

	public const TYPE_REASONS = [
		'hyperlink' => 'Hiperłącze w Figmie',
		'link_name' => 'Nazwa warstwy wskazuje przycisk lub link',
		'list' => 'Lista punktowana lub numerowana',
		'rich' => 'Formatowanie w tekście',
		'multiline' => 'Kilka wierszy lub akapitów',
		'long' => 'Długi tekst',
		'short' => 'Krótki tekst',
		'repeated_cards' => 'Powtarzalne elementy o tej samej strukturze',
		'repeated_texts' => 'Powtarzalne teksty o tej samej nazwie',
		'named_container' => 'Nazwany kontener z kilkoma tekstami',
		'image' => 'Warstwa ze zdjęciem',
		'icon' => 'Ikona w elemencie listy',
	];

	private const LINK_WORDS = ['button', 'btn', 'przycisk', 'guzik', 'cta', 'link'];

	private const GENERIC_CONTAINER = '/^(frame|group|grupa|rectangle|prostokąt|auto\s*layout|container|kontener|wrapper|content|inner|box|stack|row|column|col|div|instance|layer|warstwa|component|vector|section|sekcja|block|blok)?[\s_\-#.]*\d*$/iu';

	private const GENERIC_TEXT = '/^(text|tekst|txt|label|layer|warstwa)?[\s_\-#.]*\d*$/iu';

	private array $config;

	private int $maxDepth;

	/** @var array<string, array> */
	private array $excluded = [];

	/** @var array<string, array> */
	private array $restored = [];

	/** @var array<string, array> teksty poddrzewa (z regułami) per ID warstwy */
	private array $textsCache = [];

	private ?float $frameTop = null;

	/** Czy zapisywać wykluczenia warstw bez tekstów (tylko na poziomie sekcji, np. „Grid”). */
	private bool $recordEmpty = false;

	/** Głębokość zagnieżdżenia w elementach repeatera: ikony rozpoznajemy tylko tam. */
	private int $itemLevel = 0;

	/**
	 * @param array<string, string> $rules ID warstwy => FigmaNodeRule::INCLUDE|EXCLUDE
	 */
	public function __construct(private array $rules = [], ?array $config = null, ?int $maxDepth = null, private array $patterns = [])
	{
		$this->config = $config ?? (array) config('fillmein.figma.analysis', []);
		$this->maxDepth = $maxDepth ?? (int) config('fillmein.fields.max_depth', 3);
	}

	/**
	 * @return array{frame: array, wrappers: string[], sections: array, excluded: array, restored: array, stats: array}
	 */
	public function analyze(array $document): array
	{
		$this->excluded = [];
		$this->restored = [];
		$this->textsCache = [];

		$path = [(string) ($document['name'] ?? '')];
		$frameHeight = (float) ($document['box']['height'] ?? 0);
		$this->frameTop = isset($document['box']['y']) ? (float) $document['box']['y'] : null;
		$this->recordEmpty = true;
		$children = $this->included($document, $path);
		$wrappers = [];

		// Wrapper (np. „Content” na całą wysokość) rozpakowujemy maksymalnie dwa poziomy.
		for ($level = 0; $level < 2; $level++) {
			$only = count($children) === 1 ? $children[0] : null;

			if (! $only || ($only['type'] ?? '') === 'TEXT' || empty($only['children']) || $frameHeight <= 0
				|| (float) ($only['box']['height'] ?? 0) < 0.9 * $frameHeight) {
				break;
			}

			$wrappers[] = (string) $only['id'];
			$path[] = (string) ($only['name'] ?? '');
			$children = $this->included($only, $path);
		}

		// Puste warstwy wewnątrz sekcji nie trafiają na listę wykluczeń — także przy rozbijaniu dużych frame'ów.
		$this->recordEmpty = false;
		$frameWidth = (float) ($document['box']['width'] ?? 0);
		$candidates = $this->split(array_map(fn ($node) => [$node, $path, false], $children), $frameWidth, 0, $wrappers);
		$sections = [];

		foreach ($candidates as [$node, $nodePath, $fromSplit]) {
			$fields = ($node['type'] ?? '') === 'TEXT'
				? [$this->textField($node, 0, null)]
				: $this->fields($node, 0, [...$nodePath, (string) ($node['name'] ?? '')]);

			$sections[] = [
				'node_id' => (string) $node['id'],
				'name' => $fromSplit ? $this->splitSectionName($node, $nodePath) : $this->sectionName($node),
				'position' => count($sections),
				'meta' => $this->meta($node),
				'fields' => $this->positioned($fields),
			];
		}

		return [
			'frame' => ['node_id' => (string) ($document['id'] ?? ''), 'name' => (string) ($document['name'] ?? '')],
			'wrappers' => $wrappers,
			'sections' => $sections,
			'excluded' => array_values($this->excluded),
			'restored' => array_values($this->restored),
			'stats' => $this->stats($sections),
		];
	}

	/**
	 * Duży frame z kilkoma blokami w środku (np. cała treść podstrony w jednym „Frame 318”) rozbijamy na jego
	 * dzieci, żeby każdy blok był osobną sekcją. Rozbijamy też frame, którego jedno dziecko samo jest duże
	 * (np. pasek zakładek + jeden wysoki kontener z sekcjami). Rozbijany frame trafia do `wrappers`.
	 *
	 * @param array<int, array{0: array, 1: string[], 2: bool}> $candidates [warstwa, ścieżka rodzica, wydzielona z dużego frame'u]
	 * @return array<int, array{0: array, 1: string[], 2: bool}>
	 */
	private function split(array $candidates, float $frameWidth, int $level, array &$wrappers): array
	{
		if (empty($this->config['split_large_frames'] ?? true)) {
			return $candidates;
		}

		$result = [];
		$minHeight = max((float) ($this->config['split_min_height'] ?? 1200), (float) ($this->config['split_min_height_ratio'] ?? 1.0) * $frameWidth);
		$childMin = (float) ($this->config['split_child_min_height'] ?? 300);
		$fullWidth = (float) ($this->config['split_full_width_ratio'] ?? 0.9);

		foreach ($candidates as [$node, $path, $fromSplit]) {
			$nodePath = [...$path, (string) ($node['name'] ?? '')];
			$inner = ($node['type'] ?? '') === 'TEXT' || empty($node['children']) ? [] : $this->included($node, $nodePath);
			// Sekcja strony zajmuje pełną szerokość frame'u; wiersze listy czy karty leżą w węższym kontenerze treści.
			$blocks = array_filter($inner, fn ($child) => ($child['type'] ?? '') !== 'TEXT'
				&& (float) ($child['box']['height'] ?? 0) >= $childMin
				&& ($frameWidth <= 0 || (float) ($child['box']['width'] ?? 0) >= $fullWidth * $frameWidth)
				&& $this->texts($child, $nodePath) !== []);

			if ($level < (int) ($this->config['split_max_levels'] ?? 3)
				&& (float) ($node['box']['height'] ?? 0) >= $minHeight
				&& (count($blocks) >= 2 || array_filter($blocks, fn ($child) => (float) ($child['box']['height'] ?? 0) >= $minHeight) !== [])) {
				$wrappers[] = (string) $node['id'];
				// Dekoracje bez tekstu (wektory, poświaty, tła) nie są sekcjami — w całym frame'ie nie dawały pól.
				$content = array_values(array_filter($inner, fn ($child) => ($child['type'] ?? '') === 'TEXT' || $this->texts($child, $nodePath) !== []));
				array_push($result, ...$this->split(array_map(fn ($child) => [$child, $nodePath, true], $content), $frameWidth, $level + 1, $wrappers));
			} else {
				$result[] = [$node, $path, $fromSplit];
			}
		}

		return $result;
	}

	/**
	 * Pola z dzieci kontenera (rekurencyjnie, ze spłaszczaniem kontenerów bez znaczenia).
	 */
	private function fields(array $container, int $depth, array $path): array
	{
		$children = $this->included($container, $path);
		$textBearing = array_values(array_filter($children, fn ($child) => $this->texts($child, $path) !== []));
		$out = [];
		$count = count($children);
		$i = 0;

		while ($i < $count) {
			$child = $children[$i];
			$childPath = [...$path, (string) ($child['name'] ?? '')];

			if (($child['type'] ?? '') === 'TEXT') {
				$run = $this->run($children, $i, fn ($node) => ($node['type'] ?? '') === 'TEXT' ? $this->textName($node) : null);

				if (count($run) >= (int) ($this->config['min_text_run'] ?? 3) && $depth < $this->maxDepth) {
					$out[] = $this->repeater($run, $depth, $path, null, 'repeated_texts');
				} else {
					foreach ($run ?: [$child] as $text) {
						$out[] = $this->textField($text, $depth, $container, count($textBearing));
					}
				}

				$i += max(1, count($run));

				continue;
			}

			if (empty($child['children']) || $this->texts($child, $path) === []) {
				$media = $this->mediaField($child, $container, $children);

				if ($media !== null) {
					$out[] = $media;
				}

				$i++;

				continue;
			}

			$run = $this->run($children, $i, fn ($node) => $this->signature($node, $path));

			if (count($run) >= (int) ($this->config['min_repeat_items'] ?? 2) && $this->sameItems($run) && $depth < $this->maxDepth) {
				$out[] = $this->repeater($run, $depth, $path, count($run) === count($textBearing) ? $container : null, 'repeated_cards');
				$i += count($run);

				continue;
			}

			$items = $this->repeatedItems($child, $childPath);

			if ($items !== null && $depth < $this->maxDepth) {
				$out[] = $this->repeater($items, $depth, $childPath, $child, 'repeated_cards', (string) $child['id']);
			} elseif ($depth < $this->maxDepth
				&& count($this->texts($child, $path)) >= 2
				&& $this->meaningful((string) ($child['name'] ?? ''), self::GENERIC_CONTAINER)
				&& count($textBearing) > 1) {
				$groupFields = $this->fields($child, $depth + 1, $childPath);

				// Grupa z jednym elementem (np. tylko repeater) nic nie wnosi — zostaje sam element.
				if (count($groupFields) < 2) {
					array_push($out, ...$groupFields);
				} else {
					$out[] = [
						'node_id' => (string) $child['id'],
						'role' => 'container',
						'type' => FieldType::Group->value,
						'label' => $this->clean((string) $child['name']),
						'reason' => 'named_container',
						'figma_text' => null,
						'meta' => $this->meta($child),
						'children' => $this->positioned($groupFields),
					];
				}
			} else {
				array_push($out, ...$this->fields($child, $depth, $childPath));
			}

			$i++;
		}

		return $out;
	}

	/**
	 * Kontener, którego wszystkie dzieci z tekstem to powtórzenia tej samej struktury (np. „Karty”).
	 */
	private function repeatedItems(array $node, array $path): ?array
	{
		$children = $this->included($node, $path);
		$items = array_values(array_filter($children, fn ($child) => $this->texts($child, $path) !== []));

		if (count($items) < (int) ($this->config['min_repeat_items'] ?? 2)) {
			return null;
		}

		$textItems = array_filter($items, fn ($item) => ($item['type'] ?? '') === 'TEXT');

		// Same punkty listy jako osobne warstwy tekstowe o tej samej nazwie (np. „Punkt”).
		if (count($textItems) === count($items)) {
			$names = array_unique(array_map(fn ($item) => $this->textName($item), $items));

			return count($items) >= (int) ($this->config['min_text_run'] ?? 3) && count($names) === 1 && $names[0] !== null
				? $items
				: null;
		}

		foreach ($items as $item) {
			if (($item['type'] ?? '') === 'TEXT' || empty($item['children'])) {
				return null;
			}
		}

		$signatures = array_unique(array_map(fn ($item) => $this->signature($item, $path), $items));

		return count($signatures) === 1 && $signatures[0] !== null && $this->sameItems($items) ? $items : null;
	}

	/**
	 * Dwa kontenery o różnych nazwach to osobne bloki, które przypadkiem mają tyle samo tekstów. Od trzech
	 * sztuk struktura wystarcza: karty bywają nazwane od swojej treści („Akustyka”, „Termoizolacja”).
	 */
	private function sameItems(array $items): bool
	{
		if (count($items) > 2) {
			return true;
		}

		$names = array_map(fn ($item) => mb_strtolower($this->itemName($item)), $items);

		return count(array_unique($names)) === 1
			|| ! array_filter($items, fn ($item) => $this->meaningful((string) ($item['name'] ?? ''), self::GENERIC_CONTAINER));
	}

	/**
	 * Pole na zdjęcie albo ikonę dla warstwy bez tekstu. Reszta kształtów to dekoracja.
	 */
	private function mediaField(array $node, array $container, array $siblings): ?array
	{
		$isImage = $this->hasImage($node);

		if (! $isImage && ! $this->isIcon($node, $container, $siblings)) {
			return null;
		}

		return [
			'node_id' => (string) $node['id'],
			'role' => 'field',
			'type' => FieldType::Image->value,
			'label' => $isImage ? 'Zdjęcie' : 'Ikona',
			'reason' => $isImage ? 'image' : 'icon',
			'figma_text' => null,
			'meta' => $this->meta($node),
			'children' => [],
		];
	}

	private function hasImage(array $node): bool
	{
		if (($node['visible'] ?? true) === false) {
			return false;
		}

		return ! empty($node['image_fill'])
			|| array_filter((array) ($node['children'] ?? []), fn ($child) => is_array($child) && $this->hasImage($child)) !== [];
	}

	/**
	 * Mała grafika w elemencie listy. Strzałka obok tekstu (przycisk, link) to część tego tekstu, nie ikona karty.
	 */
	private function isIcon(array $node, array $container, array $siblings): bool
	{
		$limit = (float) ($this->config['icon_max_size'] ?? 96);
		$width = (float) ($node['box']['width'] ?? 0);
		$height = (float) ($node['box']['height'] ?? 0);
		$inline = ($container['layout'] ?? null) === 'HORIZONTAL'
			&& array_filter($siblings, fn ($sibling) => ($sibling['type'] ?? '') === 'TEXT') !== [];

		return $this->itemLevel > 0
			&& $width > 0 && $width <= $limit
			&& $height > 0 && $height <= $limit
			&& ! $inline
			&& ! $this->hasWord((string) ($container['name'] ?? ''), self::LINK_WORDS);
	}

	private function repeater(array $items, int $depth, array $path, ?array $labelNode, string $reason, ?string $nodeId = null): array
	{
		// Znany wzorzec wygrywa z pierwszym elementem: dopisanie lub przestawienie kart nie zmienia ID pól repeatera.
		$first = collect($items)->first(fn ($item) => in_array((string) $item['id'], $this->patterns, true)) ?? $items[0];
		$isText = ($first['type'] ?? '') === 'TEXT';
		$reason = $isText ? 'repeated_texts' : $reason;
		$this->itemLevel++;
		$children = $isText
			? [$this->textField($first, $depth + 1, null)]
			: $this->fields($first, $depth + 1, [...$path, (string) ($first['name'] ?? '')]);
		$this->itemLevel--;

		$label = match (true) {
			$labelNode !== null && $this->meaningful((string) ($labelNode['name'] ?? ''), self::GENERIC_CONTAINER) => $this->clean((string) $labelNode['name']),
			$isText && $this->textName($first) !== null => 'Lista: ' . $this->itemName($first),
			! $isText && $this->meaningful((string) ($first['name'] ?? ''), self::GENERIC_CONTAINER) => 'Lista: ' . $this->itemName($first),
			default => 'Lista elementów',
		};

		return [
			'node_id' => $nodeId ?? (string) $first['id'],
			'role' => 'container',
			'type' => FieldType::Repeater->value,
			'label' => $label,
			'reason' => $reason,
			'figma_text' => null,
			'meta' => $this->meta($labelNode && $nodeId ? $labelNode : $first) + [
				'pattern' => (string) $first['id'],
				'items' => array_map(fn ($item) => [
					'node_id' => (string) $item['id'],
					'name' => mb_substr((string) ($item['name'] ?? ''), 0, 190),
					'texts' => array_map(
						fn ($text) => mb_substr(trim((string) ($text['characters'] ?? '')), 0, 120),
						array_slice($isText ? [$item] : $this->texts($item, $path), 0, 4),
					),
				], array_slice($items, 0, 50)),
			],
			'children' => $this->positioned($children),
		];
	}

	/**
	 * Klasyfikacja warstwy tekstowej. Bez pewnych sygnałów zostaje najprostszy typ (text / textarea).
	 */
	private function textField(array $node, int $depth, ?array $parent, int $parentTexts = 0): array
	{
		$text = (string) ($node['characters'] ?? '');
		$trimmed = trim($text);
		$singleLine = ! str_contains($trimmed, "\n");
		$maxLink = (int) ($this->config['link_max_length'] ?? 60);
		$parentIsButton = $parent !== null && $parentTexts === 1 && $this->hasWord((string) ($parent['name'] ?? ''), self::LINK_WORDS);

		[$type, $reason] = match (true) {
			isset($node['hyperlink']) && $singleLine => [FieldType::Link, 'hyperlink'],
			$singleLine && mb_strlen($trimmed) <= $maxLink
				&& ($this->hasWord((string) ($node['name'] ?? ''), self::LINK_WORDS) || $parentIsButton) => [FieldType::Link, 'link_name'],
			! empty($node['list']) => [FieldType::Wysiwyg, 'list'],
			! empty($node['mixed_styles']) || ! empty($node['inline_links']) => [FieldType::Wysiwyg, 'rich'],
			! $singleLine => [FieldType::Textarea, 'multiline'],
			mb_strlen($trimmed) >= (int) ($this->config['textarea_min_length'] ?? 120) => [FieldType::Textarea, 'long'],
			default => [FieldType::Text, 'short'],
		};

		$meta = $this->meta($node);

		if ($type === FieldType::Link && is_string($node['hyperlink'] ?? null) && preg_match('~^(https?://|mailto:|tel:)~i', $node['hyperlink']) === 1) {
			$meta['url'] = $node['hyperlink'];
		}

		return [
			'node_id' => (string) $node['id'],
			'role' => 'field',
			'type' => $type->value,
			'label' => $this->label($node),
			'reason' => $reason,
			'figma_text' => mb_substr($text, 0, 20000),
			'meta' => $meta,
			'children' => [],
		];
	}

	/**
	 * Widoczne (niewykluczone) dzieci w kolejności czytania. Wykluczenia są zapisywane raz na warstwę.
	 */
	private function included(array $node, array $path): array
	{
		$children = array_values(array_filter((array) ($node['children'] ?? []), 'is_array'));

		foreach ($children as $index => &$child) {
			$child['_index'] = $index;
		}

		unset($child);

		$layout = $node['layout'] ?? null;
		$withBox = count(array_filter($children, fn ($child) => isset($child['box']['x'], $child['box']['y']))) === count($children);

		if ($withBox && $children !== []) {
			usort($children, function ($a, $b) use ($layout) {
				$ax = (float) $a['box']['x'];
				$bx = (float) $b['box']['x'];
				// Wiersze grupujemy w pasy po 8 px, żeby drobne przesunięcia nie psuły kolejności kart.
				$ay = (int) floor((float) $a['box']['y'] / 8);
				$by = (int) floor((float) $b['box']['y'] / 8);

				return match ($layout) {
					'HORIZONTAL' => [$ax, $a['_index']] <=> [$bx, $b['_index']],
					'VERTICAL' => [$ay, $a['_index']] <=> [$by, $b['_index']],
					default => [$ay, $ax, $a['_index']] <=> [$by, $bx, $b['_index']],
				};
			});
		}

		return array_values(array_filter($children, fn ($child) => $this->accepts($child, $path)));
	}

	private function accepts(array $node, array $path): bool
	{
		$id = (string) ($node['id'] ?? '');
		$rule = $this->rules[$id] ?? null;
		$auto = $this->autoReason($node);

		if ($rule === 'exclude') {
			$this->record($this->excluded, $node, $path, 'manual', $auto);

			return false;
		}

		if ($auto === null) {
			return true;
		}

		if ($rule === 'include') {
			$this->record($this->restored, $node, $path, $auto, $auto);

			return true;
		}

		$this->record($this->excluded, $node, $path, $auto, $auto);

		return false;
	}

	private function autoReason(array $node): ?string
	{
		$name = trim((string) ($node['name'] ?? ''));
		$type = (string) ($node['type'] ?? '');

		if (($node['visible'] ?? true) === false) {
			return 'hidden';
		}

		// „_Notatki” / „.prywatne” to konwencja warstw roboczych; „__wrapper” (BEM) to zwykły kontener.
		$private = str_starts_with($name, '.') || (str_starts_with($name, '_') && ! str_starts_with($name, '__'));

		if (in_array($type, (array) ($this->config['technical_types'] ?? []), true) || $private) {
			return 'technical';
		}

		if ($type === 'TEXT' && trim((string) ($node['characters'] ?? '')) === '') {
			return 'empty';
		}

		// Warstwy tekstowe mają zwykle nazwę = treść („Nowe menu sezonowe”), więc reguł słownych dla nich nie stosujemy.
		if ($type === 'TEXT') {
			return null;
		}

		foreach ((array) ($this->config['exclude_words'] ?? []) as $reason => $words) {
			if ($this->hasWord($name, (array) $words)) {
				return (string) $reason;
			}
		}

		$box = $node['box'] ?? null;

		if ($this->hasWord($name, (array) ($this->config['navigation_weak_words'] ?? []))
			&& isset($box['height'], $box['y']) && $this->frameTop !== null
			&& (float) $box['height'] <= (float) ($this->config['navigation_max_height'] ?? 200)
			&& (float) $box['y'] - $this->frameTop <= (float) ($this->config['navigation_top_offset'] ?? 300)) {
			return 'navigation';
		}

		return null;
	}

	private function record(array &$list, array $node, array $path, string $reason, ?string $auto): void
	{
		$id = (string) ($node['id'] ?? '');
		$texts = count($this->texts($node, $path, false));

		// Ukryte ikony i wektory wewnątrz sekcji nie wpływają na pola — nie zaśmiecamy listy wykluczeń.
		if ($texts === 0 && ! $this->recordEmpty && $reason !== 'manual') {
			return;
		}

		$list[$id] ??= [
			'node_id' => $id,
			'name' => mb_substr((string) ($node['name'] ?? ''), 0, 190),
			'type' => (string) ($node['type'] ?? ''),
			'reason' => $reason,
			'reason_label' => self::REASONS[$reason] ?? $reason,
			'auto_reason' => $auto,
			'path' => implode(' › ', array_filter(array_slice($path, 1), fn ($part) => $part !== '')),
			'texts' => $texts,
		];
	}

	/**
	 * Teksty w poddrzewie (z pominięciem wykluczonych warstw, jeśli `$respectRules`).
	 */
	private function texts(array $node, array $path, bool $respectRules = true): array
	{
		if (($node['type'] ?? '') === 'TEXT') {
			return trim((string) ($node['characters'] ?? '')) !== '' ? [$node] : [];
		}

		$id = (string) ($node['id'] ?? '');

		if ($respectRules && isset($this->textsCache[$id])) {
			return $this->textsCache[$id];
		}

		$childPath = [...$path, (string) ($node['name'] ?? '')];
		$children = $respectRules
			? $this->included($node, $childPath)
			: array_values(array_filter((array) ($node['children'] ?? []), 'is_array'));
		$texts = [];

		foreach ($children as $child) {
			array_push($texts, ...$this->texts($child, $childPath, $respectRules));
		}

		if ($respectRules) {
			$this->textsCache[$id] = $texts;
		}

		return $texts;
	}

	/**
	 * Struktura kontenera: kolejność nazw warstw tekstowych (domyślne nazwy = „T”). Teksty kart
	 * różnią się treścią, ale nie strukturą — po tym rozpoznajemy powtórzenia.
	 */
	private function signature(array $node, array $path): ?string
	{
		if (($node['type'] ?? '') === 'TEXT' || empty($node['children'])) {
			return null;
		}

		$texts = $this->texts($node, $path);

		if ($texts === []) {
			return null;
		}

		return count($texts) . ':' . implode('|', array_map(fn ($text) => $this->textName($text) ?? 'T', $texts));
	}

	/**
	 * Sąsiednie elementy od pozycji `$start` o tym samym kluczu (null = brak powtórzenia).
	 */
	private function run(array $children, int $start, callable $key): array
	{
		$first = $key($children[$start]);

		if ($first === null) {
			return [];
		}

		$run = [$children[$start]];

		for ($i = $start + 1; $i < count($children) && $key($children[$i]) === $first; $i++) {
			$run[] = $children[$i];
		}

		return $run;
	}

	/**
	 * Znormalizowana nazwa warstwy tekstowej albo null, gdy nazwa jest domyślna (= treść lub „Text 2”).
	 */
	private function textName(array $node): ?string
	{
		$name = $this->clean((string) ($node['name'] ?? ''));

		if (! $this->meaningful($name, self::GENERIC_TEXT) || $this->sameText($name, (string) ($node['characters'] ?? ''))) {
			return null;
		}

		return trim((string) preg_replace('/[\d\s_\-#.]+/u', ' ', mb_strtolower($name)));
	}

	private function label(array $node): string
	{
		$name = $this->clean((string) ($node['name'] ?? ''));

		if ($this->textName($node) !== null) {
			return mb_substr($name, 0, 190);
		}

		$excerpt = preg_replace('/\s+/u', ' ', trim((string) ($node['characters'] ?? ''))) ?? '';

		return mb_strlen($excerpt) > 50 ? rtrim(mb_substr($excerpt, 0, 50)) . '…' : ($excerpt !== '' ? $excerpt : 'Tekst');
	}

	/**
	 * Nazwa elementu listy bez numeru porządkowego („Osoba 1” → „Osoba”).
	 */
	private function itemName(array $node): string
	{
		$name = $this->clean((string) ($node['name'] ?? ''));
		$base = trim((string) preg_replace('/[\s_\-#.]*\d+$/u', '', $name));

		return $base !== '' ? $base : $name;
	}

	/**
	 * Sekcja wydzielona z dużego frame'u ma zwykle techniczną nazwę warstwy („content”) — wtedy nazwą jest
	 * jej pierwszy tekst (zwykle nagłówek).
	 */
	private function splitSectionName(array $node, array $path): string
	{
		$name = $this->clean((string) ($node['name'] ?? ''));

		if ($name !== '' && $this->meaningful($name, self::GENERIC_CONTAINER)) {
			return mb_substr($name, 0, 190);
		}

		foreach ($this->texts($node, $path) as $text) {
			$excerpt = preg_replace('/\s+/u', ' ', trim((string) ($text['characters'] ?? ''))) ?? '';

			if ($excerpt !== '') {
				return mb_strlen($excerpt) > 60 ? rtrim(mb_substr($excerpt, 0, 60)) . '…' : $excerpt;
			}
		}

		return $this->sectionName($node);
	}

	private function sectionName(array $node): string
	{
		$name = $this->clean((string) ($node['name'] ?? ''));

		return $name !== '' ? mb_substr($name, 0, 190) : 'Sekcja';
	}

	private function meta(array $node): array
	{
		return array_filter([
			'node_name' => mb_substr((string) ($node['name'] ?? ''), 0, 190),
			'node_type' => (string) ($node['type'] ?? ''),
			'index' => $node['_index'] ?? null,
			'box' => $node['box'] ?? null,
			'text_style' => $node['text_style'] ?? null,
			'component_id' => $node['component_id'] ?? null,
			'layout' => $node['layout'] ?? null,
		], fn ($value) => $value !== null && $value !== '');
	}

	private function positioned(array $fields): array
	{
		foreach (array_values($fields) as $position => $field) {
			$field['position'] = $position;
			$fields[$position] = $field;
		}

		return array_values($fields);
	}

	private function stats(array $sections): array
	{
		$stats = ['sections' => count($sections), 'fields' => 0, 'groups' => 0, 'repeaters' => 0, 'excluded' => count($this->excluded), 'restored' => count($this->restored)];

		$walk = function (array $fields) use (&$walk, &$stats) {
			foreach ($fields as $field) {
				match ($field['type']) {
					FieldType::Group->value => $stats['groups']++,
					FieldType::Repeater->value => $stats['repeaters']++,
					default => $stats['fields']++,
				};

				$walk($field['children']);
			}
		};

		foreach ($sections as $section) {
			$walk($section['fields']);
		}

		return $stats;
	}

	private function hasWord(string $name, array $words): bool
	{
		// „MainNav” → „main nav”; dzielimy na słowa po wszystkim, co nie jest literą ani cyfrą.
		$name = (string) preg_replace('/(\p{Ll})(\p{Lu})/u', '$1 $2', $name);
		$tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

		return array_intersect($tokens, array_map('mb_strtolower', $words)) !== [];
	}

	private function meaningful(string $name, string $generic): bool
	{
		$name = $this->clean($name);

		return $name !== '' && preg_match($generic, $name) !== 1;
	}

	private function sameText(string $name, string $characters): bool
	{
		$normalize = fn (string $value) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));

		// Figma nadaje warstwie tekstowej nazwę równą treści (czasem skróconą).
		return $normalize($name) === $normalize($characters)
			|| (mb_strlen($name) >= 12 && str_starts_with($normalize($characters), $normalize($name)));
	}

	/**
	 * Nazwa bez nadmiarowych spacji i prefiksów BEM („__cards” → „cards”).
	 */
	private function clean(string $value): string
	{
		$value = trim((string) preg_replace('/\s+/u', ' ', $value));
		$stripped = ltrim($value, '_ ');

		return str_starts_with($value, '__') && $stripped !== '' ? $stripped : $value;
	}

	/**
	 * Nazwa techniczna z etykiety (ASCII, podkreślenia), np. „Nagłówek sekcji” → `naglowek_sekcji`.
	 */
	public static function machineName(string $label): string
	{
		$slug = Str::slug(Str::ascii($label, 'pl'), '_');

		return mb_substr($slug !== '' ? $slug : 'pole', 0, 60);
	}
}
