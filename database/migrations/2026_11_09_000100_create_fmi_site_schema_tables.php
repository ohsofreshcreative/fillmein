<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schemat zakodowanej strony WordPress (format `fmi-site-schema` z wtyczki Fill Me In Sync) i dopasowanie
 * do niego elementów projektu.
 *
 * - fmi_site_schemas  — ostatnia wgrana wersja schematu projektu (jedna na projekt),
 * - fmi_site_mappings — podstrona → strona schematu (`target` = ID wpisu), sekcja → blok (`target` = nazwa
 *   bloku, `position` = pozycja bloku na stronie schematu), pole → pole ACF (`target` = klucz pola ACF).
 *   `target` null = „bez pary / nie importuj”. `source`: auto | manual; automat nigdy nie nadpisuje `manual`.
 *
 * Osobno od fmi_wp_mappings (ręczne nazwy ACF bez schematu), żeby projekt bez schematu działał jak dotąd.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::create('fmi_site_schemas', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('project_id')->unique()->constrained('fmi_projects')->cascadeOnDelete();
			$table->string('site_url', 500);
			$table->string('site_name', 190)->nullable();
			$table->string('schema_version', 16);
			$table->longText('document');
			$table->unsignedBigInteger('uploaded_by')->nullable();
			$table->timestamp('uploaded_at')->nullable();
			$table->timestamps();
		});

		Schema::create('fmi_site_mappings', function (Blueprint $table) {
			$table->engine = 'InnoDB';
			$table->id();
			$table->foreignId('project_id')->constrained('fmi_projects')->cascadeOnDelete();
			$table->string('mappable_type', 16);
			$table->unsignedBigInteger('mappable_id');
			$table->string('target', 190)->nullable();
			$table->integer('position')->nullable();
			$table->decimal('confidence', 4, 3)->default(0);
			$table->string('source', 8)->default('auto');
			$table->timestamps();

			$table->unique(['mappable_type', 'mappable_id']);
			$table->index('project_id');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('fmi_site_mappings');
		Schema::dropIfExists('fmi_site_schemas');
	}
};
