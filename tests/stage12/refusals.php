<?php

/**
 * Etap 12 — odmowy w logice. Inny adres jest podmieniany wyłącznie filtrem w pamięci, bez zmiany opcji w bazie.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Services\ExportValidator;
use App\Services\Import\ExportPackage;
use App\Services\Import\ImportCleanup;
use App\Services\Import\ImportEnvironment;
use App\Services\Import\ImportRefused;
use App\Services\Import\WpImporter;

$state = json_decode((string) file_get_contents('/tmp/fmi-stage12-state.json'), true);
$environment = app(ImportEnvironment::class);
$validator = app(ExportValidator::class);

Harness::test('Adres zgodny: bez odmowy', function () use ($environment) {
	$environment->assertSite();
	Harness::assert(true, 'ok');
});

foreach (['siteurl', 'home'] as $option) {
	Harness::test("Inny {$option}: odmowa, opcja w bazie bez zmian", function () use ($environment, $option) {
		global $wpdb;

		$before = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
		$swap = fn () => 'https://klient.example.com';

		add_filter("option_{$option}", $swap, 999);

		try {
			Harness::throws(ImportRefused::class, fn () => $environment->assertSite(), 'odmowa');
		} finally {
			remove_filter("option_{$option}", $swap, 999);
		}

		Harness::same($before, $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option)), 'opcja');
		$environment->assertSite();
	});
}

Harness::test('Backup: brak, pusty, w motywie, dowiązanie, stary', function () use ($environment) {
	$assert = fn (?string $path, string $message) => Harness::throws(ImportRefused::class, fn () => $environment->assertBackup($path), $message);
	$temp = tempnam(sys_get_temp_dir(), 'fmi12');

	$assert(null, 'brak');
	$assert('/tmp/nie-ma-takiego-pliku.sql', 'nie istnieje');
	$assert($temp, 'pusty');

	file_put_contents($temp, 'x');
	$environment->assertBackup($temp);

	$link = $temp . '-link';
	symlink($temp, $link);
	$assert($link, 'dowiązanie');
	unlink($link);

	touch($temp, time() - 3700);
	$assert($temp, 'stary');
	unlink($temp);

	$assert(__FILE__, 'w motywie');
});

Harness::test('Uszkodzony eksport: odmowa z listą błędów', function () use ($validator) {
	Harness::throws(ImportRefused::class, fn () => new ExportPackage('/tmp/fmi-stage12-broken.json', $validator), 'schemat', 'brak klucza „mapping”');
});

Harness::test('schema_version 2.0: odmowa wskazuje wersję', function () use ($validator) {
	Harness::throws(ImportRefused::class, fn () => new ExportPackage('/tmp/fmi-stage12-version.json', $validator), 'wersja', 'Nieobsługiwana schema_version: 2.0');
});

Harness::test('Podmieniony plik: odmowa, suma niezgodna', function () use ($validator) {
	Harness::throws(ImportRefused::class, fn () => new ExportPackage('/tmp/fmi-stage12-tampered.zip', $validator), 'suma', 'Suma pliku');
});

Harness::test('Nieistniejący typ wpisu: odmowa przed zapisem', function () use ($environment, $validator, $state) {
	$environment->loadAcf();

	$package = new ExportPackage($state['zip'], $validator);
	$package->document['pages'][0]['mapping']['post_type'] = 'nie_ma_takiego';

	Harness::throws(ImportRefused::class, fn () => app(WpImporter::class)->plan($package), 'typ', 'nie istnieje');
});

// Transakcja na $wpdb: wp_insert_post pisze przez to połączenie, a DB:: Laravela ma własne i niczego by nie cofnęło.
Harness::test('Sprzątanie: wpis bez znacznika pod elementem importu blokuje całość', function () {
	global $wpdb;

	$wpdb->query('START TRANSACTION');

	try {
		$tagged = wp_insert_post(['post_title' => 'Znacznik', 'post_type' => 'page', 'post_status' => 'draft']);
		update_post_meta($tagged, WpImporter::META_SOURCE, 'test:test');
		wp_insert_post(['post_title' => 'Obcy', 'post_type' => 'page', 'post_status' => 'draft', 'post_parent' => $tagged]);

		Harness::throws(ImportRefused::class, fn () => app(ImportCleanup::class)->plan(null), 'obce dziecko', 'bez znacznika');
	} finally {
		$wpdb->query('ROLLBACK');
	}
});

exit(Harness::summary('ETAP 12 — odmowy'));
