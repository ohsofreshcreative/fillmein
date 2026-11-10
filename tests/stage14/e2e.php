<?php

/**
 * Etap 14 — kroki E2E wykonywane przez serwisy: Figma (token jest tylko w CLI) oraz odczyt stanu projektu do
 * /tmp/fmi-stage14-e2e.json (0600). Wartości linku Figmy i tokenów nie wypisujemy.
 *
 * Tryby: figma <project>, state <project>, progress <project>, imported <source>, register <project>.
 */

use App\Integrations\Figma\FigmaClient;
use App\Models\Export;
use App\Models\Field;
use App\Models\FieldValue;
use App\Models\Page;
use App\Models\Project;
use App\Services\FigmaFrameService;
use App\Services\FigmaImportService;
use App\Services\Import\WpImporter;
use App\Support\ProjectProgress;
use Illuminate\Support\Facades\Storage;

[$mode, $subject] = $args;

$statePath = '/tmp/fmi-stage14-e2e.json';
$manifestPath = '/tmp/fmi-stage03-fixtures.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$admin = get_userdata((int) $manifest['users']['admin']);
$fail = function (string $message) {
	fwrite(STDOUT, "  FAIL  {$message}\n");
	exit(1);
};

wp_set_current_user($admin->ID);

if ($mode === 'register') {
	// Od tej chwili teardown etapu 03 i sprzątanie plików obejmują projekt utworzony przez HTTP.
	$manifest['projects']['e2e'] = (int) $subject;
	file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

	return;
}

if ($mode === 'imported') {
	global $wpdb;

	$posts = (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
		WpImporter::META_SOURCE,
		$subject,
	));
	$pages = get_posts(['post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => WpImporter::META_SOURCE, 'meta_value' => $subject]);
	$children = array_filter($pages, fn ($post) => (int) $post->post_parent !== 0);
	$attachments = get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => WpImporter::META_SOURCE, 'meta_value' => $subject]);
	$withAlt = array_filter($attachments, fn ($post) => get_post_meta($post->ID, '_wp_attachment_image_alt', true) !== '');
	$values = 0;

	foreach ($pages as $post) {
		foreach (get_post_meta($post->ID) as $key => $meta) {
			$values += $key[0] !== '_' && str_contains((string) $meta[0], 'E2E') ? 1 : 0;
		}
	}

	if ($posts === 0 || count($pages) < 2 || $children === [] || count($attachments) < 3 || $withAlt === [] || $values === 0) {
		$fail(sprintf('Import: wpisy=%d strony=%d dzieci=%d załączniki=%d z ALT=%d wartości=%d', $posts, count($pages), count($children), count($attachments), count($withAlt), $values));
	}

	fwrite(STDOUT, sprintf("  PASS  Import: wpisy=%d, strony=%d (w tym podstrony %d), załączniki=%d (z ALT %d), wartości E2E=%d\n", $posts, count($pages), count($children), count($attachments), count($withAlt), $values));

	return;
}

$project = Project::query()->findOrFail((int) $subject);

if ($mode === 'figma') {
	$url = (string) getenv('FMI_FIGMA_LIVE_URL');

	if (FigmaClient::configuredToken() === null || $url === '') {
		$fail('NOT_RUN: brak sekretów Figmy.');
	}

	$page = Page::query()->where('project_id', $project->id)->whereNotNull('figma_frame_url')->firstOrFail();
	$frames = app(FigmaFrameService::class);
	$import = app(FigmaImportService::class);

	$frames->fetch($page, $admin, true);
	$plan = $import->plan($page);

	if ($plan['counts']['create'] < 1 || $plan['truncated']) {
		$fail('Figma: pusty lub obcięty plan.');
	}

	$created = $import->apply($page, $admin, $plan['fingerprint'])['create'];
	$again = $import->plan($page);

	if ($again['has_changes']) {
		$fail('Figma: ponowny plan pokazuje zmiany.');
	}

	fwrite(STDOUT, sprintf("  PASS  Figma LIVE: frame pobrany, zaimportowano %d elementów, ponowny plan 0 zmian\n", $created));

	return;
}

if ($mode === 'progress') {
	$stats = ProjectProgress::forProject($project->id)['project'];

	if ($stats['percent'] !== 100 || ! $stats['required'] || $stats['total'] < 1) {
		$fail(sprintf('Postęp: %d%% (%d z %d, wymagane=%s)', $stats['percent'], $stats['filled'], $stats['total'], var_export($stats['required'], true)));
	}

	fwrite(STDOUT, sprintf("  PASS  Postęp klienta 100%% (%d z %d wymaganych)\n", $stats['filled'], $stats['total']));

	return;
}

$pages = Page::query()->where('project_id', $project->id)->orderBy('id')->get();
$fields = Field::query()->where('project_id', $project->id)->where('source', 'manual')->get();
$byName = [];

foreach ($fields as $field) {
	$byName[$field->label] = $field->id;
}

$export = Export::query()->where('project_id', $project->id)->latest('id')->first();

touch($statePath);
chmod($statePath, 0600);
file_put_contents($statePath, json_encode([
	'pages' => $pages->pluck('id')->all(),
	'fields' => $byName,
	'sections' => App\Models\Section::query()->where('project_id', $project->id)->where('source', 'manual')->pluck('id', 'name')->all(),
	'value' => FieldValue::query()->where('field_id', $byName['Nagłówek E2E'] ?? 0)->value('id'),
	'export' => $export?->uuid,
	'zip' => $export ? preg_replace('/json$/', 'zip', Storage::disk('fmi')->path($export->file_path)) : null,
	'source' => $export ? WpImporter::source(json_decode((string) Storage::disk('fmi')->get($export->file_path), true)) : null,
]));
