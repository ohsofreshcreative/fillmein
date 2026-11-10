<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * Idempotentny import eksportu do WordPressa: najpierw plan, przy `apply` kolejno media, grupy ACF, strony, wartości.
 *
 * Dopasowanie wyłącznie po meta `_fmi_import_*`, więc wpisy bez znacznika są poza zasięgiem. Tabele `fmi_*` nie są
 * dotykane, także dziennik. Zasady i ograniczenia: `docs/importer.md`.
 */
class WpImporter
{
	public const META_SOURCE = '_fmi_import_source';

	public const META_KEY = '_fmi_import_key';

	public const META_SHA = '_fmi_import_sha256';

	public const META_HASH = '_fmi_import_hash';

	public const META_GROUP = '_fmi_import_group';

	public const GROUP_PREFIX = 'group_fmi_test_';

	public const FIELD_PREFIX = 'field_fmi_test_';

	public const FIELD_PARENT_PREFIX = 's_';

	/** Szkic: publikacja strony kasuje opcję `fresh_site`, a importer nie zmienia opcji. */
	private const PAGE_STATUS = 'draft';

	private const COUNTERS = ['create' => 0, 'update' => 0, 'delete' => 0, 'skip' => 0];

	/**
	 * Stabilne między eksportami: UUID projektu pełni rolę `export_id`, bo nowy identyfikator każdego eksportu
	 * zmieniałby znacznik wszystkich wpisów i psuł idempotencję.
	 */
	public static function source(array $document): string
	{
		return $document['project']['slug'] . ':' . $document['project']['id'];
	}

	public static function groupKey(array $document, string $sectionId): string
	{
		return self::GROUP_PREFIX . substr(self::hex($document['project']['id']), 0, 12) . '_' . self::hex($sectionId);
	}

	public static function sectionFieldKey(string $sectionId): string
	{
		return self::FIELD_PREFIX . 's_' . self::hex($sectionId);
	}

	public static function fieldKey(string $fieldId): string
	{
		return self::FIELD_PREFIX . self::hex($fieldId);
	}

	/**
	 * Pole-opakowanie sekcji. Nazwy pól są unikalne tylko w sekcji, a wartości leżą w meta strony, więc bez niego
	 * dwa pola `naglowek` z różnych sekcji jednej strony nadpisywałyby się nawzajem.
	 */
	public static function sectionFieldName(string $sectionId): string
	{
		return self::FIELD_PARENT_PREFIX . substr(self::hex($sectionId), 0, 12);
	}

	public function plan(ExportPackage $package): array
	{
		$state = $this->analyze($package->document);

		return $this->report($state, $this->analyzeValues($package->document, $state, $state['page_ids'], $state['asset_ids']));
	}

	public function apply(ExportPackage $package): array
	{
		$document = $package->document;
		$state = $this->analyze($document);
		$report = $this->report($state, $this->analyzeValues($document, $state, $state['page_ids'], $state['asset_ids']));

		add_filter('wp_revisions_to_keep', '__return_zero');
		kses_remove_filters();

		try {
			$assetIds = $this->applyMedia($package, $state);
			$this->applyGroups($state);
			$pageIds = $this->applyPages($state);
			$this->applyValues($this->analyzeValues($document, $state, $pageIds, $assetIds));

			foreach ($this->analyzeValues($document, $state, $pageIds, $assetIds)['writes'] as $write) {
				if ($write['diff'] > 0) {
					throw new RuntimeException('Wartości po zapisie różnią się od eksportu.');
				}
			}
		} finally {
			kses_init_filters();
			remove_filter('wp_revisions_to_keep', '__return_zero');
		}

		return $report;
	}

	private function analyze(array $document): array
	{
		$source = self::source($document);

		$state = [
			'source' => $source,
			'media' => [],
			'groups' => [],
			'pages' => [],
			'page_ids' => [],
			'asset_ids' => [],
			'fields' => self::COUNTERS,
			'warnings' => [],
			'sections' => $this->sections($document),
		];

		$this->analyzeMedia($document, $state);
		$this->analyzeGroups($document, $state);
		$this->analyzePages($document, $state);

		foreach ($document['missing']['unmapped'] as $item) {
			$state['warnings'][] = sprintf('Pominięto pole „%s” (%s, %s): %s', $item['field'], $item['page'], $item['section'], $item['message']);
		}

		return $state;
	}

	/**
	 * @return array sekcje z drzewem zmapowanych pól; `content()` i `stored()` chodzą po nim tak samo
	 */
	private function sections(array $document): array
	{
		$children = [];

		foreach ($document['fields'] as $field) {
			if ($field['acf']['mapped']) {
				$children[$field['section_id'] . '|' . ($field['parent_id'] ?? '')][] = $field;
			}
		}

		$nodes = function (string $section, ?string $parent) use (&$nodes, $children): array {
			return array_map(fn (array $field) => [
				'field' => $field,
				'children' => $nodes($section, $field['id']),
			], $children[$section . '|' . ($parent ?? '')] ?? []);
		};

		$pages = array_column($document['pages'], null, 'id');
		$sections = [];

		foreach ($document['sections'] as $section) {
			$tree = $nodes($section['id'], null);

			if ($tree !== []) {
				$sections[] = ['section' => $section, 'page' => $pages[$section['page_id']], 'nodes' => $tree];
			}
		}

		return $sections;
	}

	private function analyzeMedia(array $document, array &$state): void
	{
		global $wpdb;

		$existing = [];
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT sha.post_id, sha.meta_value FROM {$wpdb->postmeta} sha
			INNER JOIN {$wpdb->postmeta} src ON src.post_id = sha.post_id AND src.meta_key = %s AND src.meta_value = %s
			INNER JOIN {$wpdb->posts} p ON p.ID = sha.post_id AND p.post_type = 'attachment'
			WHERE sha.meta_key = %s",
			self::META_SOURCE,
			$state['source'],
			self::META_SHA,
		));

		foreach ($rows as $row) {
			$existing[$row->meta_value] = (int) $row->post_id;
		}

		$seen = [];

		foreach ($document['assets'] as $asset) {
			$id = $existing[$asset['sha256']] ?? null;
			$duplicate = isset($seen[$asset['sha256']]);
			$seen[$asset['sha256']] = true;

			$action = match (true) {
				$duplicate => 'skip',
				$id === null => 'create',
				$this->assetChanged($id, $asset) => 'update',
				default => 'skip',
			};

			$state['media'][] = ['asset' => $asset, 'action' => $action, 'id' => $id];
			$state['asset_ids'][$asset['id']] = $id ?? 'new:' . $asset['sha256'];
		}
	}

	private function assetChanged(int $id, array $asset): bool
	{
		return (string) get_post_meta($id, '_wp_attachment_image_alt', true) !== (string) $asset['alt']
			|| (string) get_post($id)?->post_excerpt !== (string) $asset['caption'];
	}

	private function analyzeGroups(array $document, array &$state): void
	{
		global $wpdb;

		$existing = [];
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT p.ID, p.post_name, p.post_type,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = %s LIMIT 1) AS hash,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = %s LIMIT 1) AS grp
			FROM {$wpdb->posts} p
			WHERE p.post_type IN ('acf-field-group', 'acf-field') AND (p.post_name LIKE %s OR p.post_name LIKE %s)",
			self::META_HASH,
			self::META_GROUP,
			$wpdb->esc_like(self::GROUP_PREFIX) . '%',
			$wpdb->esc_like(self::FIELD_PREFIX) . '%',
		));

		foreach ($rows as $row) {
			$existing[$row->post_name] = $row;
		}

		foreach ($state['sections'] as $entry) {
			$section = $entry['section'];
			$key = self::groupKey($document, $section['id']);
			$definition = $this->groupDefinition($document, $entry, $key);
			$hash = $this->hash(array_diff_key($definition, ['fields' => 1]));
			$fields = [];

			$this->flatten($definition['fields'], $fields);

			$changed = false;

			foreach ($fields as $fieldKey => $fieldHash) {
				$current = $existing[$fieldKey] ?? null;
				$action = match (true) {
					$current === null => 'create',
					$current->hash !== $fieldHash => 'update',
					default => 'skip',
				};

				$state['fields'][$action]++;
				$changed = $changed || $action !== 'skip';
			}

			foreach ($existing as $name => $row) {
				if ($row->post_type === 'acf-field' && $row->grp === $key && ! isset($fields[$name])) {
					$state['fields']['delete']++;
					$changed = true;
				}
			}

			$group = $existing[$key] ?? null;

			$state['groups'][] = [
				'key' => $key,
				'definition' => $definition,
				'hash' => $hash,
				'fields' => $fields,
				'id' => $group ? (int) $group->ID : null,
				'action' => match (true) {
					$group === null => 'create',
					$changed || $group->hash !== $hash => 'update',
					default => 'skip',
				},
			];
		}
	}

	private function groupDefinition(array $document, array $entry, string $key): array
	{
		$section = $entry['section'];

		return [
			'key' => $key,
			'title' => sprintf('FMI test: %s / %s / %s', $document['project']['name'], $entry['page']['name'], $section['name']),
			'fields' => [[
				'key' => self::sectionFieldKey($section['id']),
				'label' => $section['name'],
				'name' => self::sectionFieldName($section['id']),
				'type' => 'group',
				'layout' => 'block',
				'sub_fields' => array_map(fn (array $node) => $this->fieldDefinition($node), $entry['nodes']),
			]],
			'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => $entry['page']['mapping']['post_type']]]],
			'active' => true,
			'description' => (string) $section['mapping']['acf_block'],
		];
	}

	private function fieldDefinition(array $node): array
	{
		$field = $node['field'];
		$definition = [
			'key' => self::fieldKey($field['id']),
			'label' => $field['label'],
			'name' => $field['acf']['name'],
			'type' => $field['acf']['type'],
			'required' => $field['required'] ? 1 : 0,
			'instructions' => (string) $field['instructions'],
		] + (array) $field['acf']['settings'];

		if ($node['children'] !== []) {
			$definition['sub_fields'] = array_map(fn (array $child) => $this->fieldDefinition($child), $node['children']);
		}

		return $definition;
	}

	/**
	 * @param array<string, string> $fields klucz pola → skrót definicji bez pól podrzędnych
	 */
	private function flatten(array $definitions, array &$fields): void
	{
		foreach ($definitions as $index => $definition) {
			$fields[$definition['key']] = $this->hash(array_diff_key($definition, ['sub_fields' => 1]) + ['menu_order' => $index]);
			$this->flatten($definition['sub_fields'] ?? [], $fields);
		}
	}

	private function hash(array $definition): string
	{
		return md5((string) wp_json_encode($definition));
	}

	private function analyzePages(array $document, array &$state): void
	{
		global $wpdb;

		$existing = [];
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT k.post_id, k.meta_value FROM {$wpdb->postmeta} k
			INNER JOIN {$wpdb->postmeta} src ON src.post_id = k.post_id AND src.meta_key = %s
			INNER JOIN {$wpdb->posts} p ON p.ID = k.post_id
			WHERE k.meta_key = %s AND k.meta_value LIKE 'page:%%'",
			self::META_SOURCE,
			self::META_KEY,
		));

		foreach ($rows as $row) {
			$existing[$row->meta_value] = (int) $row->post_id;
		}

		foreach ($document['pages'] as $page) {
			if (! post_type_exists($page['mapping']['post_type'])) {
				throw new ImportRefused(sprintf('Typ wpisu „%s” nie istnieje na tej stronie.', $page['mapping']['post_type']));
			}

			$id = $existing['page:' . $page['id']] ?? null;
			$parent = $page['parent_id'] ? ($existing['page:' . $page['parent_id']] ?? null) : 0;

			$state['pages'][] = [
				'page' => $page,
				'id' => $id,
				'action' => match (true) {
					$id === null => 'create',
					$this->pageChanged($id, $page, $parent, $state['source']) => 'update',
					default => 'skip',
				},
			];

			$state['page_ids'][$page['id']] = $id;
		}
	}

	private function pageChanged(int $id, array $page, ?int $parent, string $source): bool
	{
		$post = get_post($id);

		return $post->post_title !== $page['name']
			|| $post->post_name !== $this->slug($page)
			|| $post->post_type !== $page['mapping']['post_type']
			|| (int) $post->post_parent !== $parent
			|| (string) get_post_meta($id, '_wp_page_template', true) !== (string) $page['mapping']['template']
			|| get_post_meta($id, self::META_SOURCE, true) !== $source;
	}

	private function slug(array $page): string
	{
		return sanitize_title($page['mapping']['slug'] ?: $page['slug'] ?: $page['name']);
	}

	/**
	 * @return array{counters: array, writes: array}
	 */
	private function analyzeValues(array $document, array $state, array $pageIds, array $assetIds): array
	{
		$counters = self::COUNTERS;
		$writes = [];
		$top = array_column($document['values'], null, 'field_id');

		foreach ($state['sections'] as $entry) {
			$section = $entry['section'];
			$pageId = $pageIds[$section['page_id']] ?? null;
			$prefix = self::sectionFieldName($section['id']) . '_';

			[$value, $desired] = $this->content($entry['nodes'], $top, $assetIds, $prefix);

			if ($pageId === null) {
				$counters['create'] += count(array_filter($desired, fn (string $leaf) => ! in_array($leaf, ['', '[]', '0'], true)));
				$writes[] = ['section' => $section['id'], 'page' => $section['page_id'], 'value' => $value, 'diff' => 1];

				continue;
			}

			$stored = $this->stored($entry['nodes'], $pageId, $prefix);
			$diff = 0;

			foreach (array_keys($desired + $stored) as $leaf) {
				$diff += ($desired[$leaf] ?? '') !== ($stored[$leaf] ?? '') ? 1 : 0;
			}

			$counters['update'] += $diff;
			$counters['skip'] += count($desired) - min($diff, count($desired));
			$writes[] = ['section' => $section['id'], 'page' => $section['page_id'], 'value' => $value, 'diff' => $diff];
		}

		return ['counters' => $counters, 'writes' => $writes, 'pages' => $pageIds];
	}

	/**
	 * @return array{0: array, 1: array<string, string>} wartość dla `update_field` (klucze pól) i płaskie liście do porównania
	 */
	private function content(array $nodes, array $values, array $assets, string $prefix): array
	{
		$value = [];
		$leaves = [];

		foreach ($nodes as $node) {
			$field = $node['field'];
			$key = self::fieldKey($field['id']);
			$name = $prefix . $field['acf']['name'];
			$own = $values[$field['id']]['value'] ?? null;

			switch ($field['type']) {
				case 'group':
					[$value[$key], $nested] = $this->content($node['children'], $values, $assets, $name . '_');
					$leaves += $nested;
					break;

				case 'repeater':
					$value[$key] = [];
					$leaves[$name] = (string) count((array) $own);

					foreach (array_values((array) $own) as $index => $row) {
						[$cells, $nested] = $this->content($node['children'], array_column($row['values'], null, 'field_id'), $assets, "{$name}_{$index}_");
						$value[$key][] = $cells;
						$leaves += $nested;
					}

					break;

				case 'image':
					$value[$key] = $own ? ($assets[$own] ?? '') : '';
					$leaves[$name] = (string) $value[$key];
					break;

				case 'gallery':
					$value[$key] = array_map(fn (string $uuid) => $assets[$uuid], (array) $own);
					$leaves[$name] = (string) wp_json_encode(array_map('strval', $value[$key]));
					break;

				case 'link':
					$value[$key] = $own ? ['title' => $own['label'], 'url' => $own['url'], 'target' => ''] : '';
					$leaves[$name] = $own ? (string) wp_json_encode([$own['label'], $own['url']]) : '';
					break;

				default:
					$value[$key] = (string) $own;
					$leaves[$name] = (string) $own;
			}
		}

		return [$value, $leaves];
	}

	/**
	 * Obecny stan z meta strony. Czytamy meta, a nie `get_field`, bo plan powstaje też przed utworzeniem grup ACF.
	 *
	 * @return array<string, string>
	 */
	private function stored(array $nodes, int $postId, string $prefix): array
	{
		$leaves = [];

		foreach ($nodes as $node) {
			$field = $node['field'];
			$name = $prefix . $field['acf']['name'];
			$meta = get_post_meta($postId, $name, true);

			switch ($field['type']) {
				case 'group':
					$leaves += $this->stored($node['children'], $postId, $name . '_');
					break;

				case 'repeater':
					$leaves[$name] = (string) (int) $meta;

					for ($index = 0; $index < (int) $meta; $index++) {
						$leaves += $this->stored($node['children'], $postId, "{$name}_{$index}_");
					}

					break;

				case 'gallery':
					$leaves[$name] = (string) wp_json_encode(array_values(array_map('strval', array_filter((array) $meta))));
					break;

				case 'link':
					$leaves[$name] = is_array($meta) ? (string) wp_json_encode([$meta['title'] ?? '', $meta['url'] ?? '']) : '';
					break;

				default:
					$leaves[$name] = (string) $meta;
			}
		}

		return $leaves;
	}

	private function report(array $state, array $values): array
	{
		$counters = fn (array $items) => array_reduce($items, function (array $sum, array $item) {
			$sum[$item['action']]++;

			return $sum;
		}, self::COUNTERS);

		return [
			'source' => $state['source'],
			'media' => $counters($state['media']),
			'groups' => $counters($state['groups']),
			'fields' => $state['fields'],
			'pages' => $counters($state['pages']),
			'values' => $values['counters'],
			'warnings' => $state['warnings'],
		];
	}

	/**
	 * @return array<string, int|string> UUID pliku → ID załącznika
	 */
	private function applyMedia(ExportPackage $package, array $state): array
	{
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$ids = $state['asset_ids'];
		$bySha = [];

		foreach ($state['media'] as $item) {
			$asset = $item['asset'];
			$id = $item['id'] ?? ($bySha[$asset['sha256']] ?? null);

			if ($id === null) {
				$id = $this->insertAttachment($package, $asset, $state['source']);
			} elseif ($item['action'] === 'update') {
				wp_update_post(wp_slash(['ID' => $id, 'post_excerpt' => (string) $asset['caption']]));
				update_post_meta($id, '_wp_attachment_image_alt', wp_slash((string) $asset['alt']));
			}

			$bySha[$asset['sha256']] = $id;
			$ids[$asset['id']] = $id;
		}

		return $ids;
	}

	private function insertAttachment(ExportPackage $package, array $asset, string $source): int
	{
		$extension = pathinfo($asset['path'], PATHINFO_EXTENSION);
		$name = sanitize_file_name(pathinfo($asset['file_name'], PATHINFO_FILENAME)) ?: 'plik';
		$upload = wp_upload_bits($name . '.' . $extension, null, $package->bytes($asset));

		if (! empty($upload['error'])) {
			throw new RuntimeException('Nie udało się zapisać pliku w bibliotece mediów.');
		}

		$id = wp_insert_attachment(wp_slash([
			'post_mime_type' => $asset['mime'],
			'post_title' => $name,
			'post_excerpt' => (string) $asset['caption'],
			'post_status' => 'inherit',
			'meta_input' => [
				self::META_SOURCE => $source,
				self::META_KEY => 'asset:' . $asset['sha256'],
				self::META_SHA => $asset['sha256'],
			],
		]), $upload['file'], 0, true);

		if (is_wp_error($id)) {
			throw new RuntimeException('Nie udało się utworzyć załącznika.');
		}

		wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
		update_post_meta($id, '_wp_attachment_image_alt', wp_slash((string) $asset['alt']));

		return $id;
	}

	private function applyGroups(array $state): void
	{
		foreach ($state['groups'] as $group) {
			if ($group['action'] === 'skip') {
				continue;
			}

			$imported = acf_import_field_group($group['definition'] + ['ID' => $group['id'] ?? 0]);

			if (empty($imported['ID'])) {
				throw new RuntimeException('Nie udało się zapisać grupy ACF.');
			}

			$this->tag((int) $imported['ID'], $state['source'], 'group:' . $group['key'], $group['hash']);

			foreach ($group['fields'] as $key => $hash) {
				$post = acf_get_field_post($key);

				if (! $post) {
					throw new RuntimeException('Nie udało się zapisać pola ACF.');
				}

				$this->tag($post->ID, $state['source'], 'field:' . $key, $hash);
				update_post_meta($post->ID, self::META_GROUP, $group['key']);
			}
		}
	}

	private function tag(int $id, string $source, string $key, string $hash): void
	{
		update_post_meta($id, self::META_SOURCE, wp_slash($source));
		update_post_meta($id, self::META_KEY, wp_slash($key));
		update_post_meta($id, self::META_HASH, $hash);
	}

	/**
	 * @return array<string, int> UUID podstrony → ID wpisu
	 */
	private function applyPages(array $state): array
	{
		$ids = [];

		foreach ($state['pages'] as $item) {
			$page = $item['page'];
			$ids[$page['id']] = $item['id'];

			if ($item['action'] === 'skip') {
				continue;
			}

			$data = [
				'post_title' => $page['name'],
				'post_name' => $this->slug($page),
				'post_type' => $page['mapping']['post_type'],
				'post_status' => self::PAGE_STATUS,
				'post_parent' => $page['parent_id'] ? $ids[$page['parent_id']] : 0,
				'menu_order' => $page['position'],
			];

			$id = $item['id'] ? wp_update_post(wp_slash($data + ['ID' => $item['id']]), true) : wp_insert_post(wp_slash($data), true);

			if (is_wp_error($id)) {
				throw new RuntimeException('Nie udało się zapisać strony.');
			}

			$ids[$page['id']] = $id;

			update_post_meta($id, self::META_SOURCE, wp_slash($state['source']));
			update_post_meta($id, self::META_KEY, 'page:' . $page['id']);

			if ($page['mapping']['template']) {
				update_post_meta($id, '_wp_page_template', wp_slash($page['mapping']['template']));
			} else {
				delete_post_meta($id, '_wp_page_template');
			}
		}

		return $ids;
	}

	private function applyValues(array $values): void
	{
		foreach ($values['writes'] as $write) {
			if ($write['diff'] > 0) {
				update_field(self::sectionFieldKey($write['section']), $write['value'], $values['pages'][$write['page']]);
			}
		}
	}

	private static function hex(string $uuid): string
	{
		return str_replace('-', '', $uuid);
	}
}
