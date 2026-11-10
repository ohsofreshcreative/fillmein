<?php

namespace App\Services\Import;

/**
 * Sprzątanie importu: wyłącznie wpisy z `_fmi_import_source` oraz rekordy ACF z prefiksem `group_fmi_test_`.
 */
class ImportCleanup
{
	/**
	 * @return array{posts: array<int, string>, counts: array<string, int>}
	 */
	public function plan(?string $source): array
	{
		$posts = $this->targets($source);
		$this->assertNoForeignChildren(array_keys($posts));

		return ['posts' => $posts, 'counts' => array_count_values($posts)];
	}

	/**
	 * @return array<string, int>
	 */
	public function apply(?string $source): array
	{
		$plan = $this->plan($source);
		$directories = [];

		// Malejąco po ID: dzieci przed rodzicami, bo WordPress przepina dzieci usuwanego wpisu.
		$ids = array_keys($plan['posts']);
		rsort($ids);

		foreach ($ids as $id) {
			if ($plan['posts'][$id] === 'attachment') {
				$directories[dirname((string) get_attached_file($id))] = true;
				wp_delete_attachment($id, true);
			} else {
				wp_delete_post($id, true);
			}
		}

		foreach (array_keys($directories) as $directory) {
			// rmdir usuwa tylko puste katalogi miesiąca i roku.
			@rmdir($directory);
			@rmdir(dirname($directory));
		}

		return $plan['counts'];
	}

	/**
	 * @return array<int, string> ID → typ wpisu
	 */
	private function targets(?string $source): array
	{
		global $wpdb;

		$rows = $wpdb->get_results($source === null
			? $wpdb->prepare(
				"SELECT p.ID, p.post_type FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				UNION
				SELECT ID, post_type FROM {$wpdb->posts}
				WHERE post_type IN ('acf-field-group', 'acf-field') AND (post_name LIKE %s OR post_name LIKE %s)",
				WpImporter::META_SOURCE,
				$wpdb->esc_like(WpImporter::GROUP_PREFIX) . '%',
				$wpdb->esc_like(WpImporter::FIELD_PREFIX) . '%',
			)
			: $wpdb->prepare(
				"SELECT p.ID, p.post_type FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s",
				WpImporter::META_SOURCE,
				$source,
			));

		$targets = [];

		foreach ($rows as $row) {
			$targets[(int) $row->ID] = $row->post_type;
		}

		return $targets;
	}

	/**
	 * WordPress przepiąłby takie dziecko do innego rodzica, a więc zmienił wpis bez znacznika.
	 *
	 * @param int[] $ids
	 */
	private function assertNoForeignChildren(array $ids): void
	{
		global $wpdb;

		if ($ids === []) {
			return;
		}

		$list = implode(',', array_map('intval', $ids));
		$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent IN ({$list}) AND ID NOT IN ({$list}) AND post_type <> 'revision'");

		if ($count > 0) {
			throw new ImportRefused(sprintf('Wpisy bez znacznika (%d) mają rodzica wśród usuwanych. Sprzątanie przerwane.', $count));
		}
	}
}
