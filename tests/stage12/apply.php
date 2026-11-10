<?php

/**
 * Etap 12 — pierwszy import przez usługę: oczekiwane liczniki i plan zgodny z wykonaniem.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Services\ExportValidator;
use App\Services\Import\ExportPackage;
use App\Services\Import\ImportEnvironment;
use App\Services\Import\WpImporter;

$state = json_decode((string) file_get_contents('/tmp/fmi-stage12-state.json'), true);

app(ImportEnvironment::class)->assertSite();
app(ImportEnvironment::class)->loadAcf();

$package = new ExportPackage($state['zip'], app(ExportValidator::class));
$importer = app(WpImporter::class);
$planned = $importer->plan($package);
$report = $importer->apply($package);

foreach (['media', 'groups', 'fields', 'pages', 'values'] as $key) {
	fwrite(STDOUT, sprintf("  %s: utworzyć %d, zmienić %d, bez zmian %d\n", $key, $report[$key]['create'], $report[$key]['update'], $report[$key]['skip']));
}

Harness::test('Plan zgadza się z wykonaniem', function () use ($planned, $report) {
	Harness::same($planned, $report, 'raport');
});

Harness::test('Pierwszy import tworzy wszystko', function () use ($report, $package) {
	Harness::same(count($package->document['pages']), $report['pages']['create'], 'strony');
	Harness::same(4, $report['media']['create'], 'media');
	Harness::same(0, $report['pages']['update'] + $report['media']['update'] + $report['groups']['update'], 'bez aktualizacji');
	Harness::assert($report['values']['create'] > 0, 'wartości');
});

Harness::test('Pole niezmapowane pominięte z ostrzeżeniem', function () use ($report) {
	Harness::same(1, count($report['warnings']), 'ostrzeżenie');
});

Harness::test('Ponowny plan: 0 zmian', function () use ($importer, $package) {
	$again = $importer->plan($package);

	foreach (['media', 'groups', 'fields', 'pages', 'values'] as $key) {
		Harness::same(0, $again[$key]['create'] + $again[$key]['update'] + $again[$key]['delete'], $key);
	}
});

exit(Harness::summary('ETAP 12 — pierwszy import'));
