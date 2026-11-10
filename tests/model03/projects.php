<?php

/**
 * Migawka projektów i powiązań (bez treści): id, uuid, właściciele, członkowie, liczba podstron, wartości i linków
 * klientów na projekt oraz moduły, jeśli kolumny istnieją. Uruchamiane przez WP-CLI na dowolnej bazie (kopia/strona).
 * Argument: plik wynikowy JSON.
 */

global $wpdb;

$p = $wpdb->prefix;
$columns = $wpdb->get_col("SHOW COLUMNS FROM {$p}fmi_projects");
$modules = in_array('content_enabled', $columns, true) ? ', content_enabled, review_enabled' : '';
$rows = [];

foreach ($wpdb->get_results("SELECT id, uuid, author_id, manager_id, status, deleted_at{$modules} FROM {$p}fmi_projects ORDER BY id", ARRAY_A) as $row) {
	$id = (int) $row['id'];
	$row['members'] = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$p}fmi_project_members WHERE project_id = %d ORDER BY user_id", $id));
	$row['pages'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}fmi_pages WHERE project_id = %d", $id));
	$row['values'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}fmi_field_values WHERE project_id = %d", $id));
	$row['values_hash'] = (string) $wpdb->get_var($wpdb->prepare("SELECT MD5(GROUP_CONCAT(id, ':', version, ':', MD5(COALESCE(value, '')) ORDER BY id)) FROM {$p}fmi_field_values WHERE project_id = %d", $id));
	$row['client_accesses'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}fmi_client_accesses WHERE project_id = %d", $id));
	$rows[$id] = $row;
}

$caps = [];
foreach (['fmi_administrator', 'fmi_project_manager', 'fmi_employee'] as $role) {
	$caps[$role] = array_keys(array_filter((array) (get_role($role)?->capabilities ?? [])));
	sort($caps[$role]);
}

file_put_contents($args[0], json_encode([
	'has_modules' => $modules !== '',
	'has_revisions' => (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . 'fmi_review_revisions')),
	'projects' => $rows,
	'caps' => $caps,
	'marker' => (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'fmi_isolation_marker')),
], JSON_PRETTY_PRINT));
