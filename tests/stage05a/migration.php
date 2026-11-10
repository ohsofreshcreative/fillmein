<?php

/**
 * Etap 05a — test migracji poszerzającej ID warstw w ODIZOLOWANEJ bazie SQLite w pamięci
 * (Illuminate\Database z vendor/, bez WordPressa i bez żadnej bazy Local).
 *
 * Tabele odwzorowują kolumny i indeksy z migracji 000400/000500/000600/001300/001400/001500 (z prefiksem `wp_`).
 * Sprawdza: up() poszerza 6 kolumn do 255 i zachowuje indeksy; down() odmawia przy wartości > 64 znaków
 * bez zmiany schematu; po usunięciu długich wartości down() przywraca 64 i zachowuje indeksy.
 * SQLite zapisuje `varchar` bez długości i jej nie egzekwuje, więc szerokość 255 sprawdza dopiero schema.php
 * na MySQL; tu weryfikujemy wykonanie up()/down(), indeksy, nullable i logikę odmowy.
 *
 * Użycie: php tests/stage05a/migration.php   (kod 0 = PASS)
 */

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../stage03/_harness.php';

if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
	fwrite(STDOUT, "NOT_RUN: brak pdo_sqlite.\n");
	exit(3);
}

$capsule = new Capsule;
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'wp_']);

$container = new Container;
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
Container::setInstance($container);

$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];
$throws = [Harness::class, 'throws'];

// --- Schemat wyjściowy (VARCHAR(64) + indeksy jak po migracjach 000400–001500) ---

Schema::create('fmi_pages', function (Blueprint $t) {
	$t->id();
	$t->unsignedBigInteger('project_id');
	$t->string('figma_node_id', 64)->nullable();
	$t->index(['project_id', 'figma_node_id'], 'fmi_pages_figma_node_index');
});
Schema::create('fmi_sections', function (Blueprint $t) {
	$t->id();
	$t->unsignedBigInteger('page_id');
	$t->string('figma_node_id', 64)->nullable();
	$t->unique(['page_id', 'figma_node_id']);
});
Schema::create('fmi_fields', function (Blueprint $t) {
	$t->id();
	$t->unsignedBigInteger('project_id');
	$t->string('figma_node_id', 64)->nullable();
	$t->index(['project_id', 'figma_node_id']);
});
Schema::create('fmi_figma_frames', function (Blueprint $t) {
	$t->id();
	$t->unsignedBigInteger('project_id');
	$t->string('file_key', 64);
	$t->string('node_id', 64);
	$t->unique(['project_id', 'file_key', 'node_id'], 'fmi_figma_frames_node_unique');
});
Schema::create('fmi_figma_previews', function (Blueprint $t) {
	$t->id();
	$t->unsignedBigInteger('project_id');
	$t->unsignedBigInteger('frame_id');
	$t->string('file_key', 64);
	$t->string('node_id', 64);
	$t->unique(['frame_id', 'node_id'], 'fmi_figma_previews_node_unique');
	$t->index(['project_id', 'file_key', 'node_id'], 'fmi_figma_previews_lookup');
});
Schema::create('fmi_figma_node_rules', function (Blueprint $t) {
	$t->id();
	$t->unsignedBigInteger('page_id');
	$t->string('node_id', 64);
	$t->unique(['page_id', 'node_id'], 'fmi_figma_node_rules_unique');
});

$columns = [
	'fmi_pages' => 'figma_node_id', 'fmi_sections' => 'figma_node_id', 'fmi_fields' => 'figma_node_id',
	'fmi_figma_frames' => 'node_id', 'fmi_figma_previews' => 'node_id', 'fmi_figma_node_rules' => 'node_id',
];

/** Indeksy wszystkich 6 tabel: "tabela|unikalny|kolumny" (bez nazw, posortowane). */
$indexSnapshot = function () use ($columns): array {
	$result = [];

	foreach (array_keys($columns) as $table) {
		foreach (DB::select("PRAGMA index_list('wp_{$table}')") as $index) {
			if ($index->origin === 'pk') {
				continue;
			}

			$cols = array_map(fn ($c) => $c->name, DB::select("PRAGMA index_info('{$index->name}')"));
			$result[] = $table . '|' . $index->unique . '|' . implode(',', $cols);
		}
	}

	sort($result);

	return $result;
};

/** Definicje tabel (CREATE TABLE) z sqlite_master: do porównania „schemat bez zmian”. */
$ddl = fn (string $table): string => (string) DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", ["wp_{$table}"])->sql;
$schema = function () use ($columns, $ddl): array {
	$result = [];

	foreach (array_keys($columns) as $table) {
		$result[$table] = $ddl($table);
	}

	return $result;
};

$migration = require __DIR__ . '/../../database/migrations/2026_10_29_000100_widen_fmi_figma_node_ids.php';

$before = $indexSnapshot();
$long = str_repeat('x', 200);

echo "== Etap 05a: migracja na SQLite w pamięci ==\n";

Harness::test('Stan wyjściowy: 7 indeksów na kolumnach ID Figmy', function () use ($before, $same) {
	$same(7, count($before), 'Liczba indeksów przed migracją');
});

Harness::test('up(): wykonuje się bez błędu, indeksy bez zmian', function () use ($migration, $indexSnapshot, $before, $same) {
	$migration->up();

	$same($before, $indexSnapshot(), 'Indeksy po up()');
});

Harness::test('up(): nullable zachowane (figma_node_id nadal NULL, node_id nadal NOT NULL)', function () use ($ddl, $ok) {
	foreach (['fmi_pages', 'fmi_sections', 'fmi_fields'] as $table) {
		$ok(! preg_match('/"figma_node_id" varchar[^,]* not null/i', $ddl($table)), "{$table}: nadal nullable");
	}

	foreach (['fmi_figma_frames', 'fmi_figma_previews', 'fmi_figma_node_rules'] as $table) {
		$ok((bool) preg_match('/"node_id" varchar[^,]* not null/i', $ddl($table)), "{$table}: nadal NOT NULL");
	}
});

Harness::test('Po up() zapis ID 200 znaków działa we wszystkich 6 tabelach, unikalność nadal działa', function () use ($columns, $long, $throws, $same) {
	foreach ($columns as $table => $column) {
		DB::table($table)->insert(array_filter([
			'project_id' => in_array($table, ['fmi_pages', 'fmi_fields', 'fmi_figma_frames', 'fmi_figma_previews'], true) ? 1 : null,
			'page_id' => in_array($table, ['fmi_sections', 'fmi_figma_node_rules'], true) ? 1 : null,
			'frame_id' => $table === 'fmi_figma_previews' ? 1 : null,
			'file_key' => in_array($table, ['fmi_figma_frames', 'fmi_figma_previews'], true) ? 'FILEKEY123' : null,
			$column => $long,
		], fn ($v) => $v !== null));

		$same(200, (int) DB::table($table)->max(DB::raw("LENGTH({$column})")), "{$table}: pełne ID");
	}

	$throws(Illuminate\Database\QueryException::class, fn () => DB::table('fmi_figma_node_rules')->insert(['page_id' => 1, 'node_id' => $long]), 'Duplikat reguły', 'UNIQUE');
});

Harness::test('down() odmawia (RuntimeException) przy ID > 64 znaków i nie zmienia schematu ani indeksów', function () use ($migration, $schema, $indexSnapshot, $before, $throws, $same) {
	$schemaBefore = $schema();

	$throws(RuntimeException::class, fn () => $migration->down(), 'down() z długimi wartościami', 'Schemat nie został zmieniony');

	$same($schemaBefore, $schema(), 'Definicje tabel po odmowie');
	$same($before, $indexSnapshot(), 'Indeksy po odmowie');
});

Harness::test('Komunikat odmowy wymienia wszystkie 6 kolumn i nie zawiera treści ID', function () use ($migration, $columns, $long, $ok) {
	$message = '';

	try {
		$migration->assertCanShrink();
	} catch (RuntimeException $e) {
		$message = $e->getMessage();
	}

	foreach ($columns as $table => $column) {
		$ok(str_contains($message, "{$table}.{$column}"), "Wskazana kolumna {$table}.{$column}");
	}

	$ok(! str_contains($message, $long), 'Bez treści ID');
});

Harness::test('down() po usunięciu długich wartości: wykonuje się, indeksy bez zmian, dane 64 znaki zachowane', function () use ($migration, $columns, $indexSnapshot, $before, $same) {
	foreach (array_keys($columns) as $table) {
		DB::table($table)->delete();
	}

	DB::table('fmi_figma_node_rules')->insert(['page_id' => 1, 'node_id' => str_repeat('y', 64)]);

	$migration->down();

	$same($before, $indexSnapshot(), 'Indeksy po down()');
	$same(64, (int) DB::table('fmi_figma_node_rules')->max(DB::raw('LENGTH(node_id)')), 'Wartość 64 znaków zachowana');
});

exit(Harness::summary('ETAP 05a — migracja (SQLite w pamięci)'));
