<?php

/**
 * Etap 12 — zmiana jednej wartości, nowy eksport i import. Zmiana w tabelach fmi_* idzie w transakcji wycofywanej,
 * żeby porównanie po sprzątaniu dotyczyło stanu sprzed importu. Migawka meta dowodzi zmiany tylko tego pola.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Field;
use App\Models\FieldValue;
use App\Models\Project;
use App\Services\ContentService;
use App\Services\ExportService;
use App\Services\ExportValidator;
use App\Services\Import\ExportPackage;
use App\Services\Import\ImportEnvironment;
use App\Services\Import\WpImporter;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;

global $wpdb;

$state = json_decode((string) file_get_contents('/tmp/fmi-stage12-state.json'), true);
$ids = json_decode((string) file_get_contents('/tmp/fmi-stage11-ids.json'), true);
$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$admin = get_userdata((int) $manifest['users']['admin']);
$project = Project::query()->findOrFail((int) $manifest['projects']['a']);
$field = Field::query()->findOrFail($ids['fields']['title']);

DB::beginTransaction();

try {
	$value = FieldValue::query()->where('field_id', $field->id)->whereNull('row_id')->firstOrFail();
	app(ContentService::class)->save($field, 'Tytuł po zmianie', $value->version, Actor::user($admin));
	$json = ExportService::encode(app(ExportService::class)->build($project));
} finally {
	DB::rollBack();
}

$zip = '/tmp/fmi-stage12-changed.zip';
copy($state['zip'], $zip);
$archive = new ZipArchive;
$archive->open($zip);
$archive->addFromString('export.json', $json);
$archive->close();

app(ImportEnvironment::class)->loadAcf();

$snapshot = fn () => $wpdb->get_results($wpdb->prepare(
	"SELECT m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m
	INNER JOIN {$wpdb->postmeta} s ON s.post_id = m.post_id AND s.meta_key = %s ORDER BY m.meta_id",
	WpImporter::META_SOURCE,
), ARRAY_N);
$posts = fn () => $wpdb->get_results("SELECT ID, post_title, post_name, post_parent, post_modified_gmt FROM {$wpdb->posts} ORDER BY ID", ARRAY_N);
$before = $snapshot();
$postsBefore = $posts();
$package = new ExportPackage($zip, app(ExportValidator::class));
$importer = app(WpImporter::class);
$report = $importer->apply($package);
$after = $snapshot();

Harness::test('Raport: dokładnie jedna aktualizacja wartości', function () use ($report) {
	Harness::same(1, $report['values']['update'], 'wartości');
	Harness::same(0, $report['values']['create'], 'nowe wartości');

	foreach (['media', 'groups', 'fields', 'pages'] as $key) {
		Harness::same(0, $report[$key]['create'] + $report[$key]['update'] + $report[$key]['delete'], $key);
	}
});

Harness::test('Baza: zmienił się tylko jeden wiersz meta', function () use ($before, $after) {
	Harness::same(count($before), count($after), 'liczba wierszy');

	$changed = [];

	foreach ($before as $index => $row) {
		if ($row !== $after[$index]) {
			$changed[] = [$row[1], $row[2], $after[$index][2]];
		}
	}

	Harness::same(1, count($changed), 'zmienione wiersze');
	Harness::same('Tytuł strony', $changed[0][1], 'stara wartość');
	Harness::same('Tytuł po zmianie', $changed[0][2], 'nowa wartość');
});

Harness::test('Wpisy bez zmian (także czas modyfikacji)', function () use ($postsBefore, $posts) {
	Harness::same($postsBefore, $posts(), 'wp_posts');
});

Harness::test('Kolejny import nowego eksportu: 0 zmian', function () use ($importer, $package) {
	$report = $importer->plan($package);

	Harness::same(0, $report['values']['create'] + $report['values']['update'], 'wartości');
});

exit(Harness::summary('ETAP 12 — zmiana wartości'));
