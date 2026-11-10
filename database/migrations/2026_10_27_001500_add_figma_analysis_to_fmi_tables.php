<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analiza warstw Figmy i generowanie pól (etap 05). Migracja addytywna — nowe kolumny (nullable)
 * i nowa tabela; istniejące dane bez zmian.
 *
 * - `fmi_sections.figma_meta`, `fmi_fields.figma_meta` — dane źródłowe z Figmy (pozycja, kolejność
 *   w pliku, wybrane style tekstu, elementy repeatera). To nie jest konfiguracja zespołu ani treść.
 * - `fmi_figma_node_rules` — decyzje pracownika o warstwach frame'u podstrony: `exclude` (wyklucz
 *   z importu) albo `include` (przywróć warstwę wykluczoną automatycznie, np. ukrytą lub menu).
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::table('fmi_sections', function (Blueprint $table) {
			$table->json('figma_meta')->nullable()->after('figma_node_name');
		});

		Schema::table('fmi_fields', function (Blueprint $table) {
			$table->json('figma_meta')->nullable()->after('figma_text');
		});

		Schema::create('fmi_figma_node_rules', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->foreignId('page_id')->constrained('fmi_pages')->cascadeOnDelete();
			$table->string('node_id', 64);
			$table->string('node_name', 190)->nullable();
			$table->string('action', 16);
			$table->unsignedBigInteger('created_by')->nullable();
			$table->timestamps();

			$table->unique(['page_id', 'node_id'], 'fmi_figma_node_rules_unique');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_figma_node_rules');

		Schema::table('fmi_fields', function (Blueprint $table) {
			$table->dropColumn('figma_meta');
		});

		Schema::table('fmi_sections', function (Blueprint $table) {
			$table->dropColumn('figma_meta');
		});
	}
};
