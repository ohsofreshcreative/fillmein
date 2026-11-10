<?php

/**
 * Nawigacja — dane dodatkowe do manifestu etapu 03: konto WP bez fmi_access i link klienta projektu A.
 * Argument: setup | teardown. Token trafia tylko do pliku 0600 w /tmp, nie na wyjście.
 */

use App\Models\Project;
use App\Services\ClientAccessService;

$file = '/tmp/fmi-nav-extra.json';
$mode = $args[0] ?? '';

if ($mode === 'teardown') {
	$extra = json_decode((string) @file_get_contents($file), true);

	if (is_array($extra) && ! empty($extra['subscriber'])) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user((int) $extra['subscriber']);
	}

	@unlink($file);
	fwrite(STDOUT, "Nawigacja: dane dodatkowe usunięte.\n");

	return;
}

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$admin = get_user_by('id', (int) $manifest['users']['admin']);
$subscriber = wp_insert_user([
	'user_login' => 'fmitest_nav_' . $manifest['suffix'],
	'user_email' => 'fmitest_nav_' . $manifest['suffix'] . '@fmi-test.invalid',
	'user_pass' => wp_generate_password(24),
	'role' => 'subscriber',
]);

if (is_wp_error($subscriber)) {
	fwrite(STDERR, "BLOCKED: konto bez dostępu.\n");
	exit(2);
}

// Projekt A z oboma modułami (od etapu 03 Projekty pokazują tylko projekty z Design Review), B — tylko Treści.
$projectA = Project::query()->findOrFail($manifest['projects']['a']);
$projectA->update(['review_enabled' => true]);
$issued = app(ClientAccessService::class)->generate($projectA, $admin, null, 'Test nawigacji');

file_put_contents($file, json_encode([
	'subscriber' => $subscriber,
	'cookie' => LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie((int) $subscriber, time() + 3600, 'logged_in'),
	'client_path' => parse_url($issued->url(), PHP_URL_PATH),
]));
chmod($file, 0600);
fwrite(STDOUT, "Nawigacja: konto bez dostępu i link klienta gotowe.\n");
