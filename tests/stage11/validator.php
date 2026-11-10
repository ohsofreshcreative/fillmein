<?php

/**
 * Etap 11 — walidator: uszkodzone eksporty muszą być odrzucone z listą błędów wskazującą miejsce.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Enums\FieldType;
use App\Models\Project;
use App\Services\ExportService;
use App\Services\ExportValidator;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$project = Project::query()->findOrFail((int) $manifest['projects']['a']);
$validator = app(ExportValidator::class);
$good = json_decode(ExportService::encode(app(ExportService::class)->build($project)), true);
$schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/schemas/fmi-export.schema.json'), true);

$typeAt = function (array $document, string $type): int {
	foreach ($document['values'] as $index => $value) {
		if ($value['field_type'] === $type) {
			return $index;
		}
	}

	throw new RuntimeException("Brak wartości {$type}.");
};
$contains = fn (array $errors, string $needle) => (bool) array_filter($errors, fn ($error) => str_contains($error, $needle));

Harness::test('Plik JSON po dekodowaniu przechodzi walidację', function () use ($validator, $good) {
	Harness::same([], $validator->validate($good), 'błędy');
});

Harness::test('Schemat zna wszystkie FieldType', function () use ($schema) {
	$cases = array_map(fn (FieldType $type) => $type->value, FieldType::cases());
	$listed = $schema['definitions']['fieldType']['enum'];

	sort($cases);
	sort($listed);

	Harness::same($cases, $listed, 'typy');
});

$broken = [
	'brak klucza głównego' => [function (&$d) { unset($d['missing']); }, 'brak klucza „missing”'],
	'brak schema_version' => [function (&$d) { unset($d['schema_version']); }, 'brak klucza „schema_version”'],
	'niezgodna wersja główna' => [function (&$d) { $d['schema_version'] = '2.0'; }, '/schema_version: nieprawidłowy format'],
	'nieznany klucz główny' => [function (&$d) { $d['debug'] = 1; }, 'niedozwolony klucz „debug”'],
	'zły typ pozycji' => [function (&$d) { $d['pages'][0]['position'] = 'pierwsza'; }, '/pages/0/position: oczekiwano typu integer'],
	'brak klucza podstrony' => [function (&$d) { unset($d['pages'][0]['mapping']); }, '/pages/0: brak klucza „mapping”'],
	'zły UUID' => [function (&$d) { $d['project']['id'] = 'abc'; }, '/project/id: nieprawidłowy format'],
	'zły format czasu' => [function (&$d) { $d['exported_at'] = 'dziś'; }, '/exported_at: nieprawidłowy format'],
	'zły blok' => [function (&$d) { $d['sections'][0]['mapping']['acf_block'] = 'hero'; }, 'acf_block: nieprawidłowy format'],
	'zła nazwa ACF' => [function (&$d) { $d['fields'][0]['acf']['name'] = 'Zła Nazwa'; }, 'acf/name: nieprawidłowy format'],
	'nieznany typ pola' => [function (&$d) { $d['fields'][0]['type'] = 'date'; }, '/fields/0/type: wartość spoza listy'],
	'zła suma pliku' => [function (&$d) { $d['assets'][0]['sha256'] = 'abc'; }, '/assets/0/sha256: nieprawidłowy format'],
	'ścieżka serwera w pliku' => [function (&$d) { $d['assets'][0]['path'] = '/Users/x/plik.png'; }, '/assets/0/path: nieprawidłowy format'],
	'zły mime' => [function (&$d) { $d['assets'][0]['mime'] = 'text/html'; }, '/assets/0/mime: wartość spoza listy'],
	'zły powód braku' => [function (&$d) { $d['missing']['required_empty'][0]['reason'] = 'x'; }, 'reason: wartość spoza listy'],
	'powtórzone ID' => [function (&$d) { $d['fields'][1]['id'] = $d['fields'][0]['id']; }, '/fields/1/id: powtórzone ID'],
	'nieznana sekcja pola' => [function (&$d) { $d['fields'][0]['section_id'] = $d['project']['id']; }, '/fields/0/section_id: nieznana sekcja'],
	'nieznana podstrona rodzica' => [function (&$d) { $d['pages'][0]['parent_id'] = $d['project']['id']; }, '/pages/0/parent_id: nieznana podstrona'],
	'nieznane pole wartości' => [function (&$d) { $d['values'][0]['field_id'] = $d['project']['id']; }, '/values/0/field_id: nieznane pole'],
];

foreach ($broken as $name => [$damage, $expected]) {
	Harness::test("Odrzucone: {$name}", function () use ($validator, $good, $damage, $expected, $contains) {
		$document = $good;
		$damage($document);
		$errors = $validator->validate($document);

		Harness::assert($errors !== [], 'brak błędów');
		Harness::assert($contains($errors, $expected), 'oczekiwano „' . $expected . '”, jest: ' . implode('; ', array_slice($errors, 0, 3)));
	});
}

$typed = [
	'tekst jako liczba' => ['text', 5],
	'link jako tekst' => ['link', 'https://example.com'],
	'link bez adresu' => ['link', ['label' => 'x']],
	'zdjęcie jako lista' => ['image', ['abc']],
	'galeria jako tekst' => ['gallery', 'abc'],
	'repeater jako tekst' => ['repeater', 'wiersz'],
];

foreach ($typed as $name => [$type, $value]) {
	Harness::test("Odrzucone: {$name}", function () use ($validator, $good, $typeAt, $type, $value) {
		$document = $good;
		$document['values'][$typeAt($document, $type)]['value'] = $value;

		Harness::assert($validator->validate($document) !== [], 'brak błędów');
	});
}

Harness::test('Odrzucone: wartość z typem innym niż definicja pola', function () use ($validator, $good, $typeAt, $contains) {
	$document = $good;
	$document['values'][$typeAt($document, 'text')]['field_type'] = 'textarea';

	Harness::assert($contains($validator->validate($document), 'typ inny niż w definicji pola'), 'typ');
});

Harness::test('Odrzucone: nieznany plik w wartości', function () use ($validator, $good, $typeAt, $contains) {
	$document = $good;
	$document['values'][$typeAt($document, 'image')]['value'] = $document['project']['id'];

	Harness::assert($contains($validator->validate($document), 'nieznany plik'), 'plik');
});

$rows = [
	'wiersz bez values' => [function (&$row) { unset($row['values']); }, 'brak klucza „values”'],
	'wiersz bez ID' => [function (&$row) { unset($row['id']); }, 'brak klucza „id”'],
	'wiersz ze złą pozycją' => [function (&$row) { $row['position'] = -1; }, 'wartość poniżej minimum'],
	'wiersz jako tekst' => [function (&$row) { $row = 'wiersz'; }, 'oczekiwano typu object'],
	'wiersz z nieznanym kluczem' => [function (&$row) { $row['extra'] = 1; }, 'niedozwolony klucz „extra”'],
	'wartość wiersza ze złym typem' => [function (&$row) { $row['values'][0]['value'] = 7; }, '/value/0'],
];

foreach ($rows as $name => [$damage, $expected]) {
	Harness::test("Odrzucone repeater: {$name}", function () use ($validator, $good, $typeAt, $damage, $expected, $contains) {
		$document = $good;
		$damage($document['values'][$typeAt($document, 'repeater')]['value'][0]);
		$errors = $validator->validate($document);

		Harness::assert($errors !== [], 'brak błędów');
		Harness::assert($contains($errors, $expected), 'oczekiwano „' . $expected . '”, jest: ' . implode('; ', array_slice($errors, 0, 3)));
		Harness::assert($contains($errors, '/value/0'), 'ścieżka do wiersza');
	});
}

Harness::test('Odrzucone repeater: pole spoza repeatera w wierszu', function () use ($validator, $good, $typeAt, $contains) {
	$document = $good;
	$document['values'][$typeAt($document, 'repeater')]['value'][0]['values'][] = $document['values'][$typeAt($document, 'text')];

	Harness::assert($contains($validator->validate($document), 'nie należy do tego poziomu wierszy'), 'poziom');
});

Harness::test('Odrzucone repeater: pole wiersza na najwyższym poziomie', function () use ($validator, $good, $typeAt, $contains) {
	$document = $good;
	$document['values'][] = $document['values'][$typeAt($document, 'repeater')]['value'][0]['values'][0];

	Harness::assert($contains($validator->validate($document), 'nie należy do tego poziomu wierszy'), 'poziom');
});

exit(Harness::summary('ETAP 11 — walidator'));
