<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hierarchia podstron, link do pojedynczego frame'u Figmy i zgoda na duplikowanie przez klienta.
 *
 * - `parent_id` — podstrona nadrzędna; brak cykli pilnuje App\Services\PageService,
 * - `parent_key` — `parent_id` albo 0 dla korzenia. Potrzebne, bo MySQL dopuszcza wiele NULL
 *   w indeksie unikalnym, więc `unique(project_id, parent_id, slug)` nie chroniłby korzenia.
 *   Wartość utrzymuje model App\Models\Page (hook `saving`); ten sam wzorzec co `row_key`
 *   w fmi_field_values,
 * - `figma_frame_url` — link do JEDNEGO frame'u Figmy per podstrona (ustalenie produktowe).
 *   `fmi_projects.figma_file_url` zostaje jako plik nadrzędny projektu,
 * - `client_can_duplicate` — czy klient (bez konta, przez token) może skopiować tę podstronę,
 * - `duplicated_from_id` — ślad pochodzenia duplikatu; wartości duplikatu są niezależne.
 *
 * Migracja jest addytywna: nie usuwa ani nie modyfikuje istniejących kolumn i danych.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::table('fmi_pages', function (Blueprint $table) {
			$table->foreignId('parent_id')->nullable()->after('project_id')
				->constrained('fmi_pages')->cascadeOnDelete();
			$table->unsignedBigInteger('parent_key')->default(0)->after('parent_id');
			$table->string('figma_frame_url', 500)->nullable()->after('figma_node_name');
			$table->boolean('client_can_duplicate')->default(false)->after('position');
			$table->foreignId('duplicated_from_id')->nullable()->after('client_can_duplicate')
				->constrained('fmi_pages')->nullOnDelete();

			// Nazwy jawne: automatyczne przekroczyłyby limit 64 znaków identyfikatora MySQL.
			$table->index(['project_id', 'parent_key', 'position'], 'fmi_pages_tree_index');
			$table->unique(['project_id', 'parent_key', 'slug'], 'fmi_pages_parent_slug_unique');
		});
	}

	public function down(): void
	{
		Schema::table('fmi_pages', function (Blueprint $table) {
			$table->dropUnique('fmi_pages_parent_slug_unique');
			$table->dropIndex('fmi_pages_tree_index');
			$table->dropConstrainedForeignId('duplicated_from_id');
			$table->dropColumn('client_can_duplicate');
			$table->dropColumn('figma_frame_url');
			$table->dropColumn('parent_key');
			$table->dropConstrainedForeignId('parent_id');
		});
	}
};
