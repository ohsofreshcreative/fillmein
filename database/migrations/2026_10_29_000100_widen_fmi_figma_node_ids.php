<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Poszerzenie kolumn z ID warstw Figmy z VARCHAR(64) do VARCHAR(255).
 *
 * ID warstw wewnątrz instancji komponentów (`I67388:4188;123:456;…`) potrafią przekroczyć 64 znaki,
 * a wtedy zapis kończył się błędem bazy. `change()` w MySQL to `ALTER TABLE … MODIFY`, więc wszystkie
 * indeksy i klucze unique na tych kolumnach zostają (zmienia się tylko szerokość kolumny).
 *
 * Najdłuższy klucz — unique `fmi_figma_frames` (project_id, file_key 64, node_id 255) — to ok. 1,3 kB
 * w utf8mb4, co wymaga ROW_FORMAT DYNAMIC/COMPRESSED (limit 3072 B). Migracja sprawdza to przed zmianą.
 *
 * down() przywraca 64 znaki tylko wtedy, gdy żadna wartość nie jest dłuższa — inaczej MySQL
 * obciąłby ID albo zgłosił błąd w połowie operacji, więc odmawiamy zanim cokolwiek zmienimy.
 */
return new class extends Migration
{
	private const OLD_LENGTH = 64;

	private const NEW_LENGTH = 255;

	/** tabela => [kolumna => czy nullable] */
	private const COLUMNS = [
		'fmi_pages' => ['figma_node_id' => true],
		'fmi_sections' => ['figma_node_id' => true],
		'fmi_fields' => ['figma_node_id' => true],
		'fmi_figma_frames' => ['node_id' => false],
		'fmi_figma_previews' => ['node_id' => false],
		'fmi_figma_node_rules' => ['node_id' => false],
	];

	public function up(): void
	{
		$this->assertRowFormat();

		foreach (self::COLUMNS as $table => $columns) {
			Schema::table($table, function (Blueprint $blueprint) use ($columns) {
				foreach ($columns as $column => $nullable) {
					$definition = $blueprint->string($column, self::NEW_LENGTH);

					if ($nullable) {
						$definition->nullable();
					}

					$definition->change();
				}
			});
		}
	}

	public function down(): void
	{
		$this->assertCanShrink();

		foreach (self::COLUMNS as $table => $columns) {
			Schema::table($table, function (Blueprint $blueprint) use ($columns) {
				foreach ($columns as $column => $nullable) {
					$definition = $blueprint->string($column, self::OLD_LENGTH);

					if ($nullable) {
						$definition->nullable();
					}

					$definition->change();
				}
			});
		}
	}

	/**
	 * Rzuca RuntimeException, gdy któraś kolumna ma wartość dłuższą niż 64 znaki. Niczego nie zmienia.
	 * Osobna metoda, żeby logikę dało się sprawdzić bez uruchamiania rollbacku.
	 */
	public function assertCanShrink(): void
	{
		$tooLong = [];
		// Znaki, nie bajty: w MySQL LENGTH() liczy bajty, więc używamy CHAR_LENGTH(); SQLite (testy) zna tylko LENGTH().
		$length = DB::getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';

		foreach (self::COLUMNS as $table => $columns) {
			foreach (array_keys($columns) as $column) {
				$max = (int) DB::table($table)->max(DB::raw($length . '(`' . $column . '`)'));

				if ($max > self::OLD_LENGTH) {
					$tooLong[] = sprintf('%s.%s (najdłuższa wartość: %d znaków)', $table, $column, $max);
				}
			}
		}

		if ($tooLong !== []) {
			throw new RuntimeException(
				'Cofnięcie migracji przerwane: ID warstw Figmy dłuższe niż ' . self::OLD_LENGTH . ' znaków w ' . implode(', ', $tooLong)
				. '. Schemat nie został zmieniony. Skróć lub usuń te wartości świadomie, a dopiero potem cofnij migrację.'
			);
		}
	}

	/** Klucze na VARCHAR(255) w utf8mb4 przekraczają limit 767 B, jeśli tabela nie jest DYNAMIC/COMPRESSED. */
	private function assertRowFormat(): void
	{
		// ROW_FORMAT i limit długości kluczy dotyczą InnoDB; inne sterowniki (np. SQLite w testach) pomijamy.
		if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
			return;
		}

		$prefix = DB::getTablePrefix();

		foreach (array_keys(self::COLUMNS) as $table) {
			// Surowe zapytanie: builder dopisałby prefiks WordPressa do `information_schema.tables`.
			$row = DB::selectOne(
				'SELECT ROW_FORMAT AS row_format FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
				[$prefix . $table],
			);
			$format = $row->row_format ?? null;

			if ($format !== null && ! in_array(strtolower((string) $format), ['dynamic', 'compressed'], true)) {
				throw new RuntimeException(sprintf(
					'Tabela %s%s ma ROW_FORMAT=%s. Klucze na kolumnach VARCHAR(255) wymagają DYNAMIC lub COMPRESSED. Schemat nie został zmieniony.',
					$prefix,
					$table,
					$format,
				));
			}
		}
	}
};
