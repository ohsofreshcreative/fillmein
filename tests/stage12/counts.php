<?php

/**
 * Etap 12 — stan bazy do porównań. Oprócz liczby wierszy: skróty wszystkich opcji oraz wpisów bez znacznika,
 * dzięki czemu test widzi też zmianę wartości, a nie tylko liczby wierszy. Transienty i czasy w `cron` zmienia sam
 * WordPress, więc z `cron` porównujemy listę haków: dopisanie zdarzenia przez wtyczkę musi być widoczne.
 */

use App\Services\Import\WpImporter;

global $wpdb;

$name = $args[0];
$tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'fmi_') . '%'));
$counts = [
	'wp_posts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"),
	'wp_postmeta' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}"),
	'wp_users' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
	'wp_options' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}"),
	'acf_groups' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'acf-field-group'"),
	'posts_without_source' => (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s)",
		WpImporter::META_SOURCE,
	)),
	'options_hash' => md5(wp_json_encode($wpdb->get_results(
		"SELECT option_name, option_value FROM {$wpdb->options}
		WHERE option_name <> 'cron' AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%'
		ORDER BY option_name",
		ARRAY_N,
	))),
	'cron_hooks' => implode(',', (function () {
		$hooks = [];

		foreach ((array) _get_cron_array() as $events) {
			array_push($hooks, ...array_keys((array) $events));
		}

		$hooks = array_unique($hooks);
		sort($hooks);

		return $hooks;
	})()),
	'users_hash' => md5(wp_json_encode($wpdb->get_results("SELECT ID, user_login, user_pass, user_email, user_status FROM {$wpdb->users} ORDER BY ID", ARRAY_N))),
	'untagged_posts_hash' => md5(wp_json_encode($wpdb->get_results($wpdb->prepare(
		"SELECT p.ID, p.post_title, p.post_name, p.post_parent, p.post_status, p.post_modified_gmt, p.post_content FROM {$wpdb->posts} p
		WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s) ORDER BY p.ID",
		WpImporter::META_SOURCE,
	), ARRAY_N))),
];

foreach ($wpdb->get_results("SELECT post_type, COUNT(*) AS total FROM {$wpdb->posts} GROUP BY post_type ORDER BY post_type") as $row) {
	$counts['posts:' . $row->post_type] = (int) $row->total;
}

foreach ($tables as $table) {
	$counts[$table] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
}

file_put_contents("/tmp/fmi-stage12-{$name}.json", json_encode($counts, JSON_PRETTY_PRINT));
