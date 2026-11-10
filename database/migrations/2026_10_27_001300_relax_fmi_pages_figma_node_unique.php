<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ten sam frame Figmy może być użyty przez wiele podstron (ustalenie produktowe oraz warunek
 * duplikowania podstrony z zachowaniem linku do frame'a). Pierwotny `unique(project_id, figma_node_id)`
 * to uniemożliwiał, więc zamieniamy go na zwykły indeks — dopasowanie przy imporcie z Figmy
 * (etap 04) musi brać pod uwagę również `source` i `id` podstrony.
 *
 * Operacja zmienia wyłącznie indeks; żadne dane ani kolumny nie są usuwane.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::table('fmi_pages', function (Blueprint $table) {
			$table->dropUnique(['project_id', 'figma_node_id']);
			$table->index(['project_id', 'figma_node_id'], 'fmi_pages_figma_node_index');
		});
	}

	public function down(): void
	{
		Schema::table('fmi_pages', function (Blueprint $table) {
			$table->dropIndex('fmi_pages_figma_node_index');
			$table->unique(['project_id', 'figma_node_id']);
		});
	}
};
