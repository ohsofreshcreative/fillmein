<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drzewo warstw frame'u (`fmi_figma_frames.document`) jako LONGTEXT zamiast JSON.
 *
 * MariaDB trzyma JSON jako LONGTEXT z CHECK (JSON_VALID(...)), a JSON_VALID odrzuca dokumenty zagnieżdżone
 * głębiej niż 32 poziomy. Każda warstwa Figmy to obiekt + tablica `children`, więc od ~15 poziomów warstw
 * zapis kończył się błędem 4025 (CONSTRAINT `…document` failed). Kolumna nie jest odpytywana funkcjami JSON —
 * model rzutuje ją na tablicę (cast `array`), tak jak `fmi_site_schemas.document`.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::table('fmi_figma_frames', function (Blueprint $table) {
			$table->longText('document')->nullable()->change();
		});

		$this->dropJsonCheck();
	}

	/**
	 * Powrót do JSON może się nie udać na MariaDB, jeśli w tabeli są już głębsze drzewa — wtedy najpierw
	 * pobierz te frame'y ponownie z mniejszą głębokością (`fillmein.figma.node_depth`).
	 */
	public function down(): void
	{
		Schema::table('fmi_figma_frames', function (Blueprint $table) {
			$table->json('document')->nullable()->change();
		});
	}

	/**
	 * MODIFY zwykle usuwa kolumnowy CHECK aliasu JSON; na wypadek starszej MariaDB usuwamy go jawnie.
	 */
	private function dropJsonCheck(): void
	{
		if (! str_contains(strtolower((string) DB::selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
			return;
		}

		$table = DB::getTablePrefix() . 'fmi_figma_frames';
		$exists = DB::selectOne(
			'SELECT COUNT(*) AS c FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
			[$table, 'document'],
		);

		if ((int) ($exists->c ?? 0) > 0) {
			DB::statement('ALTER TABLE `' . str_replace('`', '', $table) . '` DROP CONSTRAINT `document`');
		}
	}
};
